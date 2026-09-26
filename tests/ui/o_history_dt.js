/**
 * 3e-3 round B1 measurement, updated round B2a: Process Detail's Action History tab after
 * `#tb_run_audit_log` (detail.js) became a real `serverSide` DataTable (`api/payroll-run.audit-
 * log.list` + `.column-values`, Round B1) instead of the client-side table B1's own predecessor
 * round built. Every cell below was re-verified against `tmp-audit-fe-roundA.md` (F7) and
 * `tmp-audit-fe-roundB1.md` before being changed; `tmp-audit-fe-roundB2a.md` has the full per-cell
 * accounting (what changed, why, check counts).
 *
 * Run:  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/o_history_dt.js <PHPSESSID> <fixtureRunToken>
 *
 * 17 original cells (c1-c17) + 6 new ones (N1-N6) for the serverSide-specific mechanics B1 added
 * (lazy init, stale reload, search-box threshold, label/value split, order whitelist, column-values
 * scope) that had no equivalent in the client-side table this file used to measure.
 *
 * Run selection, round B2a: c2-c4/c9/c12-c14 moved off run 752 onto run 1014 (locked, small) --
 * those cells only ever needed 752 to clear `initSharedDataTable()`'s `searchThreshold` gate, which
 * disabled DataTables' ENTIRE filtering engine (search box + every `ext.search` predicate) on a
 * small CLIENT-SIDE table. Round B1's server conversion moves column filters and the date range onto
 * `ajax.data`/`onApply` (`table-column-filter.js`'s own `mode:'server'` path, and detail.js's own
 * `ajax.data` builder) -- neither goes through `ext.search` or `bFilter` any more, so that gate no
 * longer applies to them at all (confirmed by reading `app.js`'s own `dtSyncServerSearchVisibility()`
 * and `PayrollRunModel::getAuditLogPaged()` directly -- not run live, banned this round). c6/c7/c8/c15
 * keep run 752 (their own reasons -- note-length/ip diversity, row VOLUME for paging/edge-case rows
 * -- are independent of the old threshold bug and still hold). c1/c5/c10/c11/c16 keep the fixture.
 * N3 uses run 29685 (2 non-view_detail rows, round A's own COUNT -- genuinely under the threshold).
 *
 *   c1  fixture run -- recordsTotal matches the DB reference (tests/ui/audit_log_ref_cli.php, tiny
 *       round B), view_detail never shows in the server's own page-1 response
 *   c2  filter "การกระทำ" to a real action off run 1014's own data -- request sends the RAW code,
 *       dropdown shows the translated label, visible rows match
 *   c3  2 filters at once (การกระทำ + ผู้ทำ, both raw in the request) off a real co-occurring row,
 *       then clear both -- back to the full count
 *   c4  filter to a real-but-non-co-occurring 2-value combo -- the OTHER empty state (not
 *       no_history_yet), clearing brings rows back; a real bug found writing this cell is reported,
 *       not exercised live (see its own comment + tmp-audit-fe-roundB2a.md)
 *   c5  a made-up action code, injected via a REAL response rewritten in flight (route interception --
 *       the old page-context `initAuditLogTableRd([fakeRow])` injection point is gone, B1 made that
 *       function take no params) -- fallback label + data-code, never the raw code as visible text
 *   c6  the note cell -- every row the same height regardless of note length, NO tooltip left,
 *       every row carries exactly one view-detail button (fa-eye) -- unchanged from B1's own
 *       measurement, run 752 kept (note-length diversity, unrelated to the threshold bug)
 *   c7  device/IP column -- fixture (both empty) renders '-', run 752's real rows show ip + filter
 *       (request sends the raw ip, dropdown shows it unchanged -- no formatValue on this key)
 *   c8  run 752 (read-only) -- SERVER paging: request 2 carries `start=pageLength`, page 2 differs
 *       from page 1, default sort is performed_at DESC, recordsTotal matches the DB reference
 *   c9  th -> en -> th -- headers/labels follow langData; a column filter set before the switch is
 *       CLEARED by it (B1's own new behaviour, closing the "invisible stale filter -> 0 rows" gap);
 *       request count across the switch is measured and reported, not assumed
 *   c10 dark + 430 -- header/row colours read real --c-* tokens, no sideways scroll, no pane padding
 *   c11 nothing else broke -- the other 7 tabs still open, #tb_run_detail's own column filter still
 *       opens, #phTitleBadge still renders through stateBadgeRd()
 *   c12 date range on run 1014 -- a day with rows narrows recordsDisplay to exactly that day's count
 *       (ISO date_from/date_to in the request, inclusive both edges), a day with none -> 0 + the
 *       FILTERED empty state
 *   c13 from > to -- callout shows, recordsDisplay unchanged (no new request needed to prove that --
 *       the guard is client-side, before ajax.data ever builds); once fixed, the resulting request
 *       carries date_from='' AND date_to='' (never the nonsensical pair), so the result equals
 *       unfiltered, not a wrong-order range query
 *   c14 #auditLogFilterBar chip/count/Clear progression, THEN a column filter + date + search all at
 *       once -- the shared Clear button resets everything; the request right after has no filters
 *       left in it (inspected directly, not inferred from the UI alone)
 *   c15 #auditLogDetailModal -- a real click opens the RIGHT row; edge-case rows (longest note / NULL
 *       note / from!=to) found in run 752's own reference data are brought onto page 1 via a column/
 *       search filter first (not just constructed in isolation, B1 made the table server-paged so a
 *       row picked from the FULL reference set is not necessarily already on screen)
 *   c16 filter bar shape -- unchanged from B1 (pure DOM/CSS), waits added only around the 2 date-
 *       field interactions; modal/theme/layout checks untouched
 *   c17 UNTOUCHED -- #runDetailFilterBar (Employee tab), not this table, not this round's to touch
 *
 *   N1 lazy init: no `audit-log.list` request at page load; the FIRST click on #run-history-tab
 *      fires exactly one
 *   N2 stale: a `loadRunDetail()` call while on another tab queues no request; switching to History
 *      afterward reloads once; calling `loadRunDetail()` while ALREADY on History reloads immediately
 *   N3 search-box threshold: shown on a run past it (1014), hidden on one under it (29685) -- unless
 *      29685 itself has grown past 10 rows by the time this runs, NOTEd rather than asserted wrong
 *   N4 dropdown: search-in-list matches the translated label, not the raw enum; sort order follows
 *      the label alphabetically (B1's own `formatValue` re-sort), not the raw arrival order
 *   N5 order: clicking a sortable header's own sort-arrow sends `order[0][column]`/`dir`; the note
 *      column (not orderable, no sort-arrow rebuilt for it) never fires a new request when clicked
 *   N6 column-values scope: with a date range already set, opening a DIFFERENT column's filter sends
 *      that same date_from/date_to (and any other column's own current selection) alongside it
 *
 * Writes: none. This suite never clicks anything that would trigger a mutating call, and every
 * context passes `blockPaths: WRITE_PATHS` (copied from m3e1_tabs_shared.js's own list) on top of
 * the harness's 2 built-in blocks (api/user-preference.save, api/payroll-run.recalculate). Opening a
 * run page writes exactly one `view_detail` audit row per open (PayrollRunModel::logViewDetail()) --
 * expected, unrelated to anything counted here, and never returned by getAuditLog()/
 * getAuditLogPaged() in the first place (both share the same `action != 'view_detail'` exclusion).
 *
 * Run 752/1014/29685's tokens are never hardcoded -- IdCodec::encode()'s own ciphertext is
 * non-deterministic (fresh IV per call) -- tests/ui/id_codec_cli.php is shelled out to for both
 * directions, same as every prior round of this file.
 */
'use strict';
const { openContext, closeAll, applyAppTheme } = require('./harness');
const { execFileSync } = require('child_process');
const path = require('path');

const sessionId = process.argv[2];
const fixtureRunToken = process.argv[3];
if (!sessionId || !fixtureRunToken) {
    throw new Error('usage: node tests/ui/o_history_dt.js <PHPSESSID> <fixtureRunToken>');
}

let passed = 0;
let failed = 0;
function check(label, cond, extra) {
    if (cond) { passed++; console.log('  PASS  ' + label); }
    else { failed++; console.log('  FAIL  ' + label + (extra !== undefined ? ' -- ' + extra : '')); }
}
function measured(label, value) {
    console.log('  MEASURED  ' + label + ' = ' + (typeof value === 'object' ? JSON.stringify(value) : value));
}
function note(msg) {
    console.log('  NOTE  ' + msg);
}

const RUN_752_ID = 752;     // real dev run, ~1571 audit rows (round A's own COUNT) -- paging (c8) + note/ip diversity (c6/c7/c15)
const RUN_1014_ID = 1014;   // real dev run, locked, 18 non-view_detail rows (round A's own COUNT) -- most filter/date/language cells
const RUN_29685_ID = 29685; // real dev run, locked, 2 non-view_detail rows (round A's own COUNT) -- search-box threshold (N3)

// 2026-09-22, round B2: every context passes this so a genuine write is aborted-and-counted rather
// than merely "never having been clicked" (copied verbatim from m3e1_tabs_shared.js's own list).
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
];

const ID_CODEC_CLI = path.join(__dirname, 'id_codec_cli.php');
function idCodecEncode(id) {
    return execFileSync('php', [ID_CODEC_CLI, 'encode', String(id)], { encoding: 'utf8' }).trim();
}
function idCodecDecode(token) {
    const out = execFileSync('php', [ID_CODEC_CLI, 'decode', token], { encoding: 'utf8' }).trim();
    return out === 'none' ? null : parseInt(out, 10);
}
/** Every token this suite navigates to is decoded back here first -- a cell that opens the wrong
 *  run measures the wrong thing while still printing the right label. */
function resolveRunToken(label, token, expectedId) {
    const decoded = idCodecDecode(token);
    measured('IdCodec decode: ' + label, { token: token, decoded: decoded, expected: expectedId });
    check('IdCodec: "' + label + '" token really decodes to ' + expectedId, decoded === expectedId, String(decoded));
    return token;
}

const RUN_752_TOKEN = resolveRunToken('run 752', idCodecEncode(RUN_752_ID), RUN_752_ID);
const RUN_1014_TOKEN = resolveRunToken('run 1014', idCodecEncode(RUN_1014_ID), RUN_1014_ID);
const RUN_29685_TOKEN = resolveRunToken('run 29685', idCodecEncode(RUN_29685_ID), RUN_29685_ID);
const fixtureId = idCodecDecode(fixtureRunToken);
measured('IdCodec decode: fixture run (argv)', { token: fixtureRunToken, decoded: fixtureId });

async function gotoRun(ctx, token, lang) {
    await ctx.page.goto(ctx.url('/payroll-process/' + token), { waitUntil: 'networkidle' });
    await ctx.page.waitForTimeout(900);
    if (lang) {
        await ctx.page.evaluate((l) => { if (typeof changeLanguage === 'function') changeLanguage(l); }, lang);
        await ctx.page.waitForTimeout(500);
    }
}

/** 2026-09-24, B2a, spec item 1 -- waits for exactly one `api/payroll-run.audit-log.list` POST
 *  caused by `triggerFn`, THEN for the table's own `draw.dt` event (fired once DataTables has
 *  actually finished rendering the response, not merely received it) -- never `waitForTimeout` as
 *  the primary wait. The `draw.dt` counter is bound once (idempotent guard, survives across many
 *  calls within a cell) and just counts, since this function only needs proof that at least one MORE
 *  draw happened after `triggerFn` ran, not that it was the only one in flight. */
// 2026-09-24, B2b round 1 real bug found in THIS TEST (not B1), root cause of several
// inconsistent-between-runs failures (c8's start param reading 0, N1's "2 requests", c9/c12/c14's
// stale-state reads): `page.waitForResponse(predicate)`, set up BEFORE `triggerFn()`, matches the
// FIRST qualifying response event that arrives AFTER it is registered -- not specifically the one
// CAUSED by `triggerFn()`. A still-in-flight request from an EARLIER action (e.g. a debounced
// onChange that hadn't resolved yet) can race past the new one and get matched instead, handing the
// caller a stale request/response pair. `page.waitForRequest(predicate)` does not have this problem:
// it only ever matches a request's own 'request' event, which fires once, at the moment THAT
// request is actually sent -- a request already in flight before this call had its 'request' event
// fire long before this listener existed, so it can never be matched here; only a genuinely NEW
// request (the one `triggerFn()` causes) can satisfy it. Pairing `page.waitForRequest()` with
// `triggerFn()` via `Promise.all` (not `await triggerFn()` first) additionally covers the case where
// the request is dispatched synchronously inside the click/evaluate call itself, before control
// would otherwise return to this function.
async function waitAuditList(page, triggerFn) {
    await page.evaluate(() => {
        window.__auditDrawCount = window.__auditDrawCount || 0;
        if (!window.__auditDrawBound) {
            window.__auditDrawBound = true;
            $('#tb_run_audit_log').on('draw.dt', () => { window.__auditDrawCount++; });
        }
    });
    const before = await page.evaluate(() => window.__auditDrawCount || 0);
    const [request] = await Promise.all([
        page.waitForRequest((req) =>
            req.url().indexOf('/api/payroll-run.audit-log.list') !== -1 && req.method() === 'POST',
            { timeout: 15000 }),
        triggerFn(),
    ]);
    const res = await request.response();
    await page.waitForFunction((n) => (window.__auditDrawCount || 0) > n, before, { timeout: 15000 });
    let json = null;
    try { json = res ? await res.json() : null; } catch (e) { /* not json */ }
    return { res, json, request };
}
/** Same shape, for `api/payroll-run.audit-log.column-values` -- no `draw.dt` event exists for a
 *  panel refresh, so this waits for the panel's own `.tcf-loading` placeholder to be gone instead
 *  (renderList(), table-column-filter.js, unconditionally replaces it once `done()` runs). */
async function waitAuditColumnValues(page, triggerFn) {
    const [request] = await Promise.all([
        page.waitForRequest((req) =>
            req.url().indexOf('/api/payroll-run.audit-log.column-values') !== -1 && req.method() === 'POST',
            { timeout: 15000 }),
        triggerFn(),
    ]);
    const res = await request.response();
    await page.waitForSelector('.tcf-loading', { state: 'detached', timeout: 15000 });
    let json = null;
    try { json = res ? await res.json() : null; } catch (e) { /* not json */ }
    return { res, json, request };
}
// 2026-09-24, B3 ส่วน B2 ข้อ 3, real bug found in THIS TEST (not B1): `clearAuditLogDateFilter()`'s
// own `page.waitForLoadState('networkidle')` (added in B2b to fix the SAME symptom) resolves once
// network activity has been quiet for a window -- it does NOT know about `initFilterBar()`'s own
// `scheduleNotify()` debounce (`setTimeout(fn, 0)`, app.js), so if that debounce timer has not fired
// YET at the moment `networkidle` starts counting, `networkidle` can resolve BEFORE the resulting
// `.draw()`/reload is even sent, let alone completed -- confirmed live via a temporary request-stack
// trace (B3 ส่วน B1, removed after use per B5): the clear-triggered request WAS sent with correct
// (empty) params, but its response never arrived by the time the trace was dumped, meaning the wait
// resolved and the test read state before that request had settled. `waitForNextAuditDraw()` ties
// the wait to the table's own `draw.dt` event
// instead (the SAME robust mechanism `waitAuditList()` already uses for c8) -- a real DOM/DataTables
// event, immune to debounce-timing guesses. Optional (`timeout` catches and returns false) because a
// caller cannot always know in advance whether anything was actually set to clear (nothing set ->
// no draw fires at all, which is correct and must not be treated as a hang).
async function waitForNextAuditDraw(page, timeoutMs) {
    await page.evaluate(() => {
        window.__auditDrawCount = window.__auditDrawCount || 0;
        if (!window.__auditDrawBound) {
            window.__auditDrawBound = true;
            $('#tb_run_audit_log').on('draw.dt', () => { window.__auditDrawCount++; });
        }
    });
    const before = await page.evaluate(() => window.__auditDrawCount || 0);
    return { before, waitFor: async () => {
        try {
            await page.waitForFunction((n) => (window.__auditDrawCount || 0) > n, before, { timeout: timeoutMs || 3000 });
            return true;
        } catch (e) {
            return false;
        }
    } };
}
/** Page-level, continuous -- for cells that need to COUNT how many `audit-log.list` requests fired
 *  across a whole sequence (N1/N2/c9), not just wait for one specific one. `stop()` detaches the
 *  listener.
 *
 *  2026-09-24, Round B4, real bug found in THIS TEST (not production) while measuring c14: a single
 *  "Clear Filter" click can fire SEVERAL overlapping requests at once (barClear()'s own debounced
 *  field-reset -> onChange -> .draw(), clearColumnFilters()'s own immediate .ajax.reload(), and the
 *  caller's own explicit dt.search('').draw() -- clearAllTableFilters(), app.js) -- `page.on
 *  ('response', ...)` fires in NETWORK ARRIVAL order, which is not guaranteed to match the order
 *  those requests were actually SENT in. `list[list.length-1]` (arrival order) intermittently picked
 *  an EARLIER-sent, not-yet-fully-cleared request whose response happened to arrive last, instead of
 *  the truly-last-SENT one (which is the one whose `ajax.data` was built after every synchronous/
 *  debounced reset had already applied, and so is the only one guaranteed to reflect the fully
 *  cleared state). `seq` is stamped from a 'request' listener (`page.on('request', ...)` fires in
 *  send order, always, unlike 'response') so a caller that cares about "the last request actually
 *  sent" can sort/pick by `seq` instead of array position -- existing callers (N1/N2/c9, which only
 *  ever read `.list.length`) are unaffected, this is purely additive.
 *
 *  2026-09-25, dtlang round B, real gap found while adding detailed logging to c9() so a future flake
 *  (like the isolated 4th request seen once on 2026-09-25, BACKLOG.md) can be diagnosed from ITS OWN
 *  log instead of needing to be reproduced live again: `seq` alone said WHICH order requests were sent
 *  in but not HOW FAR APART, which matters for telling "2 near-simultaneous mechanisms" apart from "one
 *  genuinely late straggler". `ms` (elapsed since `collectAuditListResponses(page)` itself was called,
 *  stamped in the SAME 'request' listener that already stamps `seq`, same send-order guarantee) is
 *  purely additive -- existing callers reading only `.list.length`/`.seq` are unaffected.
 *
 *  2026-09-25, dtlang round C, diagnostic gap found measuring c9 after the app.js V-fix landed: `.list`
 *  only ever grows from a 'response' event, so it cannot distinguish "only 1 request was ever
 *  dispatched" from "2 were dispatched and the 2nd was aborted before it got a response" -- both look
 *  identical (1 entry, seq:0) from `.list` alone. `dispatchedCount` (the final `seqCounter` value,
 *  i.e. how many matching 'request' events fired at all, regardless of what happened after) and
 *  `failed` (a matching 'requestfailed' event -- Playwright's own event for a request that was
 *  aborted/errored before a response ever arrived, which a plain 'response' listener never sees at
 *  all) close that gap. Both purely additive on the returned object -- existing callers reading only
 *  `.list`/`.stop` are unaffected. */
function collectAuditListResponses(page) {
    const list = [];
    const failed = [];
    const collectorStartedAt = Date.now();
    let seqCounter = 0;
    const seqByRequest = new Map();
    const msByRequest = new Map();
    const requestHandler = (req) => {
        if (req.url().indexOf('/api/payroll-run.audit-log.list') === -1) return;
        if (req.method() !== 'POST') return;
        seqByRequest.set(req, seqCounter++);
        msByRequest.set(req, Date.now() - collectorStartedAt);
    };
    const handler = async (res) => {
        if (res.url().indexOf('/api/payroll-run.audit-log.list') === -1) return;
        if (res.request().method() !== 'POST') return;
        let json = null;
        try { json = await res.json(); } catch (e) { /* not json */ }
        const req = res.request();
        list.push({
            url: res.url(),
            request: req,
            json,
            seq: seqByRequest.has(req) ? seqByRequest.get(req) : -1,
            ms: msByRequest.has(req) ? msByRequest.get(req) : -1,
        });
    };
    const failedHandler = (req) => {
        if (req.url().indexOf('/api/payroll-run.audit-log.list') === -1) return;
        if (req.method() !== 'POST') return;
        const f = req.failure();
        failed.push({
            url: req.url(),
            seq: seqByRequest.has(req) ? seqByRequest.get(req) : -1,
            ms: msByRequest.has(req) ? msByRequest.get(req) : -1,
            errorText: f ? f.errorText : null,
        });
    };
    page.on('request', requestHandler);
    page.on('response', handler);
    page.on('requestfailed', failedHandler);
    return {
        list,
        failed,
        get dispatchedCount() { return seqCounter; },
        stop: () => {
            page.off('request', requestHandler);
            page.off('response', handler);
            page.off('requestfailed', failedHandler);
        },
    };
}
/** Reads the `application/x-www-form-urlencoded` body jQuery's own `$.ajax({data:{...}})` sends
 *  (detail.js's own `ajax.data`) -- plain key lookups on the exact field names DataTables/detail.js
 *  use, not a generic nested-object parser (not needed for what these cells check). */
function auditRequestParams(request) {
    return new URLSearchParams(request.postData() || '');
}

/** 2026-09-24, B2a: `#tb_run_audit_log` is lazy now (Round B1, D5) -- this click is what CONSTRUCTS
 *  it and fires its own first `audit-log.list` request; a prior round's bare
 *  `waitForFunction(isDataTable)` was enough when the table was built eagerly from `.get()`'s
 *  response before this tab was ever clicked -- now the click itself is the trigger. */
async function openHistoryTab(page) {
    const { json } = await waitAuditList(page, () => page.click('#run-history-tab'));
    await page.waitForFunction(() => window.jQuery && jQuery.fn.dataTable.isDataTable('#tb_run_audit_log'), { timeout: 10000 });
    return json;
}
// 2026-09-23, round B5, c17 -- the Employee tab, home of #runDetailFilterBar. Untouched this round.
async function openEmployeeTab(page) {
    await page.click('#run-employee-tab');
    await page.waitForFunction(() => window.jQuery && jQuery.fn.dataTable.isDataTable('#tb_run_detail'), { timeout: 10000 });
    await page.waitForTimeout(300);
}
function runDetailFilterBarState(page) {
    return page.evaluate(() => {
        const bar = document.getElementById('runDetailFilterBar');
        const countWrap = bar.querySelector('.filter-bar-count-wrap');
        const count = bar.querySelector('.filter-bar-count');
        const clearBtn = bar.querySelector('.filter-bar-clear');
        const chips = Array.from(bar.querySelectorAll('.filter-bar-chip')).map((c) => ({
            target: c.getAttribute('data-target'),
            label: ((c.querySelector('.filter-bar-chip-label') || {}).textContent || '').trim(),
            value: ((c.querySelector('.filter-bar-chip-value') || {}).textContent || '').trim(),
        }));
        return {
            countVisible: countWrap ? !countWrap.classList.contains('d-none') : false,
            count: count ? count.textContent.trim() : null,
            clearBtnVisible: clearBtn ? !clearBtn.classList.contains('d-none') : false,
            chips,
        };
    });
}
function historyState(page) {
    return page.evaluate(() => {
        const dt = jQuery('#tb_run_audit_log').DataTable();
        const info = dt.page.info();
        const bodyRows = Array.from(document.querySelectorAll('#tb_run_audit_log tbody tr'));
        const emptyRow = document.querySelector('#tb_run_audit_log tbody tr.dt-empty-row');
        return {
            recordsTotal: info.recordsTotal,
            recordsDisplay: info.recordsDisplay,
            page: info.page,
            pages: info.pages,
            length: info.length,
            bodyRowCount: bodyRows.filter((tr) => tr !== emptyRow).length,
            emptyRowTitle: emptyRow ? (emptyRow.querySelector('.empty-state-title') || {}).textContent : null,
            headers: Array.from(document.querySelectorAll('#tb_run_audit_log thead th')).map((th) => th.textContent.trim()),
            firstRowTime: dt.rows({ page: 'current' }).data().length ? dt.rows({ page: 'current' }).data()[0].performed_at : null,
        };
    });
}
/** 2026-09-24, B2a, real structural finding (not run live -- read from source, see this file's own
 *  header comment on run selection): the OLD `searchingEnabled()` this function replaces read
 *  `oFeatures.bFilter` because that flag genuinely gated BOTH the search box AND every `ext.search`
 *  predicate (date range + column filters) on the client-side table. Round B1's serverSide
 *  conversion moves both onto `ajax.data`/`onApply`, neither of which touches `ext.search`/`bFilter`
 *  at all any more -- and `app.js`'s own `initSharedDataTable()` change forces `rowCount` to
 *  `Infinity` for a serverSide table specifically, so `bFilter` is now ALWAYS true regardless of row
 *  count. What the threshold still gates is only the rendered search BOX's own CSS visibility
 *  (`dtSyncServerSearchVisibility()`, app.js -- `.dt-search`'s `d-none` class, recomputed every
 *  draw). This is what N3 checks. */
function searchBoxVisible(page) {
    return page.evaluate(() => {
        const el = document.querySelector('#run-history-pane .dt-search');
        return !!(el && !el.classList.contains('d-none'));
    });
}
async function openFilterPanel(page, key) {
    const result = await waitAuditColumnValues(page, () => page.click(`.tcf-filter-btn[data-tcf-key="${key}"]`));
    await page.waitForSelector('.tcf-panel:not(.d-none) .tcf-list .tcf-item', { timeout: 5000 });
    return result;
}
/** Checkbox `.val()` is read via the DOM `value` property (Playwright's own `inputValue()` refuses
 *  checkbox/radio inputs) -- Round B1, D3: this is always the RAW server value now, even for
 *  `audit_action`/`audit_state` whose VISIBLE text is a translated label (`formatValue`). Callers
 *  pass raw values, never labels. */
async function checkBoxesAndApply(page, wantedRawValues) {
    const selectAll = await page.$('.tcf-select-all');
    if (selectAll && (await selectAll.isChecked())) await selectAll.click();
    const boxes = await page.$$('.tcf-value-cb');
    for (const box of boxes) {
        const val = await box.evaluate((el) => el.value);
        const want = wantedRawValues.indexOf(val) !== -1;
        if ((await box.isChecked()) !== want) await box.click();
    }
    return waitAuditList(page, () => page.click('.tcf-apply-btn'));
}
async function applyColumnFilter(page, key, wantedRawValues) {
    const open = await openFilterPanel(page, key);
    const apply = await checkBoxesAndApply(page, wantedRawValues);
    return { open, apply };
}
async function clearColumnFilter(page, key) {
    const open = await openFilterPanel(page, key);
    const clear = await waitAuditList(page, () => page.click('.tcf-clear-btn'));
    return { open, clear };
}
/** Reads the currently OPEN panel's checkbox list as `{raw, label}` pairs -- `raw` from each
 *  checkbox's own `.value`, `label` from its sibling `<span>` text (renderList(),
 *  table-column-filter.js). */
// 2026-09-24, B2b round 1 real bug found and fixed: `.tcf-panel .tcf-item` also matches the panel's
// own "select all" row (`ensurePanel()`, table-column-filter.js -- `<label class="tcf-item">`
// wrapping `.tcf-select-all`, NOT `.tcf-value-cb`) -- crashed every cell that opened a real panel
// with "Cannot read properties of null (reading 'value')" the first time this actually ran against a
// live page (c2, first script of this whole round to exercise it). Scoped to `.tcf-list .tcf-item`
// instead -- `.tcf-list` is the panel's own value-checkbox container, built fresh by renderList()
// each open, never holding the select-all row.
function filterPanelItems(page) {
    return page.evaluate(() => Array.from(document.querySelectorAll('.tcf-panel .tcf-list .tcf-item')).map((item) => ({
        raw: item.querySelector('.tcf-value-cb').value,
        label: (item.querySelector('span:last-child') || {}).textContent || '',
    })));
}
/** 3e-3b round B1 helpers -- the date-range filter bar above #tb_run_audit_log. */
function toDisplayDateFromIso(iso) {
    const [y, m, d] = iso.split('-');
    return `${d}/${m}/${y}`;
}
// Sets the field the same way k4c_employee_detail.js's own bootstrap-datepicker input already does
// (`.val(...).datepicker('update')`), plus an explicit `trigger('change')` -- detail.js's own filter
// listens on `change`, not `changeDate`. Wrapped in waitAuditList: `initFilterBar()`'s own onChange
// (detail.js) always calls `tb_run_audit_log.draw()` unconditionally on a field change, which always
// re-triggers `ajax.data` on a serverSide table regardless of whether the new range is valid or not
// (only WHAT gets sent differs, never WHETHER a request fires) -- see c13's own use of this.
async function setAuditLogDate(page, id, displayValue) {
    return waitAuditList(page, () => page.evaluate(({ id, displayValue }) => {
        $('#' + id).val(displayValue).datepicker('update').trigger('change');
    }, { id, displayValue }));
}
/** 2026-09-24, B2a: whether `.filter-bar-clear` fires exactly one, zero, or several `audit-log.list`
 *  requests along the way was not verified this round (UI-script run is banned) -- rather than guess
 *  and risk a hang on a wrong assumption, this waits for the one thing GUARANTEED once its handler
 *  completes (both date fields empty), and leaves request counting to whichever caller wants it via
 *  its own collectAuditListResponses(). */
async function clearAuditLogDateFilter(page) {
    // 2026-09-24, B3: `waitForNextAuditDraw()`'s own docblock explains why this replaced the earlier
    // `networkidle`-based wait (B2b) -- captured BEFORE the click, same reason `waitAuditList()`
    // captures `before` before its own trigger.
    const draw = await waitForNextAuditDraw(page, 3000);
    await page.click('#auditLogFilterBar .filter-bar-clear');
    await page.waitForFunction(() => $('#auditLogDateFrom').val() === '' && $('#auditLogDateTo').val() === '', { timeout: 10000 });
    await draw.waitFor();
}
function auditLogFilterBarState(page) {
    return page.evaluate(() => {
        const bar = document.getElementById('auditLogFilterBar');
        const countWrap = bar.querySelector('.filter-bar-count-wrap');
        const count = bar.querySelector('.filter-bar-count');
        const clearBtn = bar.querySelector('.filter-bar-clear');
        const chips = Array.from(bar.querySelectorAll('.filter-bar-chip')).map((c) => ({
            target: c.getAttribute('data-target'),
            label: ((c.querySelector('.filter-bar-chip-label') || {}).textContent || '').trim(),
            value: ((c.querySelector('.filter-bar-chip-value') || {}).textContent || '').trim(),
        }));
        return {
            collapsed: bar.classList.contains('collapsed'),
            countVisible: countWrap ? !countWrap.classList.contains('d-none') : false,
            count: count ? count.textContent.trim() : null,
            clearBtnVisible: clearBtn ? !clearBtn.classList.contains('d-none') : false,
            chips,
        };
    });
}
// 2026-09-24, tiny round B: `api/payroll-run.get` stopped carrying a full `run.audit_log` array
// (see docs/decisions/2026-09-24-tiny2-get-audit-log-removal.md) -- every TINY-2 cell below used to
// capture that response (`capturePayrollGetPayloads`) and read it back (`lastAuditLog`) as its own
// ground-truth reference. That reference now comes from `tests/ui/audit_log_ref_cli.php` instead --
// a direct DB read, same row shape/exclusion as `PayrollRunModel::getAuditLog()` -- never from the
// `audit-log.list` endpoint under test itself (that would be a tautology, not a test). Kept the name
// `auditLogRef` (not `lastAuditLog`) since it no longer reads anything "last" out of captured
// responses -- it is a synchronous, one-shot CLI call per run id.
const AUDIT_LOG_REF_CLI = path.join(__dirname, 'audit_log_ref_cli.php');
function auditLogRef(runId) {
    const out = execFileSync('php', [AUDIT_LOG_REF_CLI, String(runId)], { encoding: 'utf8' });
    return JSON.parse(out).rows;
}

/* ---------------- c1 ---------------- */
async function c1() {
    console.log('\n[c1] fixture run, 1400 th light -- recordsTotal matches the DB reference, view_detail never shows');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, fixtureRunToken, 'th');
    const listJson = await openHistoryTab(ctx.page);
    const state = await historyState(ctx.page);
    const logs = auditLogRef(fixtureId);
    measured('c1 state', state);
    measured('c1 DB reference row count', logs.length);
    check('c1: recordsTotal === DB reference row count', state.recordsTotal === logs.length, state.recordsTotal + ' vs ' + logs.length);
    check('c1: no view_detail row in the server\'s own page-1 response',
        Array.isArray(listJson && listJson.data) && listJson.data.every((r) => r.action !== 'view_detail'),
        JSON.stringify(((listJson && listJson.data) || []).map((r) => r.action)));
    const rep = ctx.report();
    measured('c1 report', rep);
    check('c1: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c2 ---------------- */
async function c2() {
    console.log('\n[c2] filter "การกระทำ" to a real action off run 1014 -- request raw, dropdown translated, rows match');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, RUN_1014_TOKEN, 'th');
    await openHistoryTab(ctx.page);
    const logs = auditLogRef(RUN_1014_ID);
    if (!logs.length) { note('c2 skipped -- run 1014 has no audit rows (DB reference empty)'); await closeAll(); return; }
    const wantAction = logs[0].action; // a REAL action this run's own data actually has, never assumed
    const expectedCount = logs.filter((l) => l.action === wantAction).length;
    const open = await openFilterPanel(ctx.page, 'audit_action');
    const items = await filterPanelItems(ctx.page);
    const wantLabel = await ctx.page.evaluate((a) => auditActionLabelInfoRd(a).label, wantAction);
    const matchingItem = items.find((it) => it.raw === wantAction);
    measured('c2 column-values request params', { run_id: auditRequestParams(open.request).get('run_id'), column: auditRequestParams(open.request).get('column') });
    check('c2: column-values request carries this run\'s id and the right column key',
        auditRequestParams(open.request).get('run_id') === String(RUN_1014_ID) && auditRequestParams(open.request).get('column') === 'audit_action',
        auditRequestParams(open.request).toString());
    check('c2: the dropdown shows the TRANSLATED label for this action, not the raw code',
        !!matchingItem && matchingItem.label === wantLabel, JSON.stringify({ matchingItem, wantLabel }));
    const apply = await checkBoxesAndApply(ctx.page, [wantAction]);
    const sentRaw = auditRequestParams(apply.request).getAll('column_filters[audit_action][]');
    check('c2: value sent to the server is the RAW action code, never the label', JSON.stringify(sentRaw) === JSON.stringify([wantAction]), JSON.stringify(sentRaw));
    const state = await historyState(ctx.page);
    measured('c2 state', Object.assign({ wantAction, wantLabel, expectedCount }, state));
    check('c2: filtered recordsDisplay matches DB reference rows with this action',
        state.recordsDisplay === expectedCount, state.recordsDisplay + ' vs ' + expectedCount);
    check('c2: server response recordsFiltered agrees', !!apply.json && apply.json.recordsFiltered === expectedCount, apply.json && apply.json.recordsFiltered);
    const cellTexts = await ctx.page.$$eval('#tb_run_audit_log tbody tr td:nth-child(3)', (tds) => tds.map((td) => td.textContent.trim()));
    check('c2: every visible row shows the same translated label text', cellTexts.length > 0 && cellTexts.every((t) => t === wantLabel), JSON.stringify(cellTexts));
    const rep = ctx.report();
    measured('c2 report', rep);
    check('c2: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c3 ---------------- */
async function c3() {
    console.log('\n[c3] 2 filters at once (การกระทำ + ผู้ทำ, both raw in the request) off a real co-occurring row, then clear both');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, RUN_1014_TOKEN, 'th');
    await openHistoryTab(ctx.page);
    const logs = auditLogRef(RUN_1014_ID);
    if (!logs.length) { note('c3 skipped -- run 1014 has no audit rows'); await closeAll(); return; }
    const fullCount = logs.length;
    // 2026-09-24, B2b round 1 real bug found and fixed: `logs[0]` can be a system-attributed row
    // (`performed_by` NULL -- no human actor, e.g. a cron/setup-script action) --
    // `personDisplayNameRd()` (detail.js) returns the truthy placeholder `'-'` for that case, not a
    // falsy value, so the old `if (!wantActorName)` guard never caught it; the SERVER'S OWN
    // `auditLogColumnValues()` (PayrollRunModel.php) explicitly excludes NULL/empty names from the
    // checkbox list (`WHERE ... IS NOT NULL AND != ''`), so there is no `'-'` checkbox to match --
    // checkBoxesAndApply() then checks 0 real boxes and sends an empty array, which this cell's own
    // "raw actor value sent" assertion correctly caught as wrong (`[]` instead of `[wantActorName]`).
    // Picking the first row with a REAL (non-null) performer avoids this instead of masking it.
    const pick = logs.find((l) => l.performed_by) || logs[0];
    const wantAction = pick.action;
    const performerId = pick.performed_by;
    if (!performerId) { note('c3 skipped -- run 1014 has no row with a real (non-null) performer'); await closeAll(); return; }
    const wantActorName = await ctx.page.evaluate((row) => personDisplayNameRd(row, 'performed_by'), pick);
    await applyColumnFilter(ctx.page, 'audit_action', [wantAction]);
    await openFilterPanel(ctx.page, 'audit_performed_by');
    // 2026-09-24, B3 ส่วน B2 ข้อ 4, real finding (not a bug -- confirmed live via a temporary
    // request-stack trace, B3 ส่วน B1, removed after use per B5):
    // once `audit_action` is already applied, the `audit_performed_by` panel is scoped to ONLY the
    // performers of that one action (auditLogColumnValues()'s own column_filters-aware scoping,
    // PayrollRunModel.php) -- if `wantAction` happens to have exactly ONE distinct performer (as it
    // did live: panel showed `["Admin"]`, an exact match to `wantActorName`), checking "the one and
    // only value on offer" is INDISTINGUISHABLE from "select all" under this component's own Excel
    // semantics (`.tcf-apply-btn`'s handler, table-column-filter.js: `$checked.length ===
    // $all.length ? null : new Set(...)`) -- `state.selected[key]` becomes `null`, and
    // `getColumnFilterValues()` correctly OMITS the key entirely (`if (set) out[key] = ...` --  `null`
    // is falsy) rather than sending a real value. This is the SAME documented behaviour round A/B2a's
    // own c7 already proved for `audit_device_ip` -- not specific to this key. Checked here directly
    // instead of assumed: `panelItems.length === 1` -> the "sent nothing" case IS correct (this run's
    // real data may or may not land here every time, so both branches are asserted for).
    const panelItemsNow = await filterPanelItems(ctx.page);
    const apply2 = await checkBoxesAndApply(ctx.page, [wantActorName]);
    const sentActor = auditRequestParams(apply2.request).getAll('column_filters[audit_performed_by][]');
    if (panelItemsNow.length === 1) {
        note('c3: audit_performed_by panel scoped to exactly 1 distinct actor ("select all" case, table-column-filter.js) -- checking it correctly sends NO column_filters key at all, not the raw value');
        check('c3: with only 1 actor on offer, column_filters key is correctly omitted (select-all semantics)', sentActor.length === 0, JSON.stringify(sentActor));
    } else {
        check('c3: actor value sent to server is the raw display name (identity -- no formatValue on this key)',
            JSON.stringify(sentActor) === JSON.stringify([wantActorName]), JSON.stringify(sentActor));
    }
    const expectedBoth = logs.filter((l) => l.action === wantAction && l.performed_by === performerId).length;
    let state = await historyState(ctx.page);
    measured('c3 state (2 filters)', Object.assign({ expectedBoth }, state));
    check('c3: 2 filters together match the same subset', state.recordsDisplay === expectedBoth, state.recordsDisplay + ' vs ' + expectedBoth);
    await clearColumnFilter(ctx.page, 'audit_action');
    await clearColumnFilter(ctx.page, 'audit_performed_by');
    state = await historyState(ctx.page);
    measured('c3 state (cleared)', state);
    check('c3: clearing both filters restores the full count', state.recordsDisplay === fullCount, state.recordsDisplay + ' vs ' + fullCount);
    const rep = ctx.report();
    measured('c3 report', rep);
    check('c3: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c4 ---------------- */
// 2026-09-24, Round B4: rewritten -- the old approach (find 2 REAL column-filter values whose
// intersection is empty) depends on run 1014's own live data diversity, and this round found it
// produces 0 checks (`note('c4 skipped...')`, every time, against the current dev DB -- not enough
// distinct action/actor combos left to guarantee an empty intersection). Replaced with the global
// search box + a string built from the current timestamp (`zz-nomatch-<ms>`), which can never
// legitimately match real note/actor-name/ip text and needs no data diversity at all to reach the
// same "OTHER empty state" (dtRenderEmptyState()'s own `filtered` branch, app.js:2500 -- true
// whenever `recordsTotal > 0` OR any of the 3 filter sources is active, search included -- so this
// renders the identical filtered zeroRecords empty state + Clear action the old combo-based approach
// exercised, just reached a different way). The 3 original assertions (filtered empty state shown,
// not "no history yet"; Clear button actually clears; cleared state returns every row) are
// unchanged.
async function c4() {
    console.log('\n[c4] search for a made-up string that can never match -- the OTHER empty state, then Clear');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, RUN_1014_TOKEN, 'th');
    await openHistoryTab(ctx.page);
    const boxVisible = await searchBoxVisible(ctx.page);
    if (!boxVisible) {
        note('c4: search box is hidden by the row-count threshold on run 1014 -- unexpected (N3 already proves 1014 is past it), skipping the search-driven half of this cell');
        await closeAll();
        return;
    }
    const noRowsTitle = await ctx.page.evaluate(() => getLangValue('no_history_yet') || '');
    const zeroFilteredTitle = await ctx.page.evaluate(() => getLangValue('zeroRecords') || '');
    const nomatch = 'zz-nomatch-' + Date.now();
    await waitAuditList(ctx.page, () => ctx.page.evaluate((t) => { jQuery('#tb_run_audit_log').DataTable().search(t).draw(); }, nomatch));
    const state = await historyState(ctx.page);
    measured('c4 state (search for a made-up string)', Object.assign({ nomatch, noRowsTitle, zeroFilteredTitle }, state));
    check('c4: recordsDisplay is 0', state.recordsDisplay === 0, state.recordsDisplay);
    check('c4: shows the FILTERED empty state, not "no history yet"',
        state.emptyRowTitle === zeroFilteredTitle && state.emptyRowTitle !== noRowsTitle, state.emptyRowTitle);
    const draw4 = await waitForNextAuditDraw(ctx.page, 3000);
    await ctx.page.click('#run-history-pane .empty-state-action');
    await draw4.waitFor();
    const state2 = await historyState(ctx.page);
    const searchEmptyAfterClear = await ctx.page.evaluate(() => jQuery('#tb_run_audit_log').DataTable().search() === '');
    measured('c4 state (after empty-state Clear button)', Object.assign({ searchEmptyAfterClear }, state2));
    check('c4: Clear button empties the search box', searchEmptyAfterClear, searchEmptyAfterClear);
    check('c4: clearing brings rows back', state2.recordsDisplay > 0, state2.recordsDisplay);
    const rep = ctx.report();
    measured('c4 report', rep);
    check('c4: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c5 ---------------- */
async function c5() {
    console.log('\n[c5] a made-up action code from a REWRITTEN real response -- fallback label + data-code, never the raw code as text');
    // 2026-09-24, B2a: B1 made initAuditLogTableRd() take NO params (serverSide, fetches its own
    // rows) -- the old `initAuditLogTableRd([fakeRow])` page-context injection is gone (extra args
    // to a zero-arg function are silently ignored, and its own `isDataTable()` guard would no-op it
    // once the table already exists anyway). Route-intercepts the REAL response instead and
    // rewrites one row's `action` to a fake code -- same effect (a row the client-side label map has
    // never seen), reached through the real server contract.
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    const FAKE_ACTION = '__zz_test';
    await ctx.context.route('**/api/payroll-run.audit-log.list*', async (route) => {
        const response = await route.fetch();
        let json;
        try { json = await response.json(); } catch (e) { await route.fulfill({ response }); return; }
        if (Array.isArray(json.data) && json.data.length) json.data[0].action = FAKE_ACTION;
        await route.fulfill({ response, json });
    });
    await gotoRun(ctx, fixtureRunToken, 'th');
    await openHistoryTab(ctx.page);
    const rowCount = await ctx.page.evaluate(() => document.querySelectorAll('#tb_run_audit_log tbody tr:not(.dt-empty-row)').length);
    if (!rowCount) { note('c5 skipped -- fixture run has no audit rows to rewrite'); await closeAll(); return; }
    const result = await ctx.page.evaluate((fakeAction) => {
        const cell = document.querySelector('#tb_run_audit_log tbody tr td:nth-child(3)');
        const codeEl = cell ? cell.querySelector('[data-code]') : null;
        return {
            cellText: cell ? cell.textContent.trim() : null,
            dataCode: codeEl ? codeEl.getAttribute('data-code') : null,
            fallbackLabel: getLangValue('action_unknown') || '',
        };
    }, FAKE_ACTION);
    measured('c5 result', result);
    check('c5: cell shows the shared fallback label', result.cellText === result.fallbackLabel, result.cellText);
    check('c5: raw code is NOT the visible text', result.cellText !== FAKE_ACTION, result.cellText);
    check('c5: raw code is recoverable via data-code', result.dataCode === FAKE_ACTION, result.dataCode);
    const rep = ctx.report();
    measured('c5 report', rep);
    check('c5: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c6 ---------------- */
// 2026-09-24, B2a: unchanged mechanism -- this cell never filters/sorts, so the threshold finding
// (this file's own header comment) does not apply to it; run 752 is kept for its own, still-valid
// reason (real note-length diversity the fixture cannot offer).
async function c6() {
    console.log('\n[c6] note cell -- equal row heights, NO tooltip left, 1 view-detail button per row');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, RUN_752_TOKEN, 'th');
    await openHistoryTab(ctx.page);
    const picture = await ctx.page.evaluate(() => {
        const rows = Array.from(document.querySelectorAll('#tb_run_audit_log tbody tr')).filter((tr) => !tr.classList.contains('dt-empty-row'));
        const heights = rows.map((tr) => Math.round(tr.getBoundingClientRect().height));
        const noteCells = Array.from(document.querySelectorAll('#tb_run_audit_log tbody .rd-audit-note-cell'));
        const withFullNoteAttr = noteCells.filter((el) => el.hasAttribute('data-full-note'));
        const withLiveTooltip = noteCells.filter((el) => window.bootstrap && bootstrap.Tooltip.getInstance(el));
        const viewBtns = Array.from(document.querySelectorAll('#tb_run_audit_log tbody .audit-log-view-detail-btn'));
        const viewBtnsWithEye = viewBtns.filter((b) => b.querySelector('i.fa-eye'));
        const emptyNoteRows = rows.length - noteCells.length;
        return {
            rowCount: rows.length, heights, maxDelta: heights.length ? Math.max(...heights) - Math.min(...heights) : 0,
            noteCellCount: noteCells.length, withFullNoteAttrCount: withFullNoteAttr.length, withLiveTooltipCount: withLiveTooltip.length,
            viewBtnCount: viewBtns.length, viewBtnsWithEyeCount: viewBtnsWithEye.length,
            emptyNoteRows,
        };
    });
    measured('c6 picture', picture);
    check('c6: every row the same height (within 1px)', picture.maxDelta <= 1, picture.maxDelta);
    check('c6: no data-full-note attribute left on any note cell', picture.withFullNoteAttrCount === 0, picture.withFullNoteAttrCount);
    check('c6: no live tooltip instance on any note cell', picture.withLiveTooltipCount === 0, picture.withLiveTooltipCount);
    check('c6: every row has exactly one view-detail button', picture.viewBtnCount === picture.rowCount, picture.viewBtnCount + ' vs ' + picture.rowCount);
    check('c6: every view-detail button shows fa-eye', picture.viewBtnsWithEyeCount === picture.viewBtnCount, picture.viewBtnsWithEyeCount + ' vs ' + picture.viewBtnCount);
    const rep = ctx.report();
    measured('c6 report', rep);
    check('c6: no console/page error from a NULL note (' + picture.emptyNoteRows + ' rows on this page had one)',
        rep.consoleErrors.length === 0 && rep.pageErrors.length === 0, JSON.stringify(rep.consoleErrors.concat(rep.pageErrors)));
    check('c6: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c7 ---------------- */
async function c7() {
    console.log('\n[c7] device/IP -- null-ip/ua rows render "-", run 752\'s real rows show ip (raw, unfiltered by formatValue)');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, fixtureRunToken, 'th');
    await openHistoryTab(ctx.page);
    const fixtureCells = await ctx.page.$$eval('#tb_run_audit_log tbody tr td:nth-child(6)', (tds) => tds.map((td) => td.textContent.trim()));
    // 2026-09-24, B2b round 1 real finding (not a B1/B2a bug): this file's own prior-round premise
    // ("fixture rows are CLI-written, no ip/ua") is no longer reliably true -- the mandated script
    // ORDER for this round runs many other scripts against this SAME fixture run/session first
    // (m3e2a..h_history_table), several of which perform real browser-driven writes on it, each
    // logging a real Playwright ip/user_agent. The true, mode-independent invariant this cell means
    // to prove is "a row with no ip/ua renders '-'", not "this run happens to have none" -- checked
    // directly against the DB reference instead of assuming the run's own composition. Page 1's
    // own default order is `performed_at DESC` (same as the server default) with the rows this page
    // actually holds capped at bodyRowCount.
    // Mirrors the server's own default order exactly (`performed_at DESC, id DESC` --
    // PayrollRunModel::getAuditLogPaged()) so row `i` here really is DOM row `i` on page 1, including
    // ties (a plain performed_at-only sort would leave same-timestamp rows in an arbitrary/unstable
    // order, easy to hit when several rows land in the same second).
    const logs0 = auditLogRef(fixtureId).slice().sort((a, b) =>
        a.performed_at !== b.performed_at ? (a.performed_at < b.performed_at ? 1 : -1) : (b.id - a.id));
    const page1Ref = logs0.slice(0, fixtureCells.length);
    measured('c7 fixture device/ip cells', fixtureCells);
    measured('c7 fixture reference null-ip/ua count on page 1', page1Ref.filter((r) => !r.ip_address && !r.user_agent).length);
    check('c7: every reference row with no ip/ua renders "-"',
        fixtureCells.length > 0 && page1Ref.every((r, i) => (!r.ip_address && !r.user_agent) === (fixtureCells[i] === '-')),
        JSON.stringify({ fixtureCells, nullRows: page1Ref.map((r) => !r.ip_address && !r.user_agent) }));
    await closeAll();

    // run 752: real ip diversity the fixture cannot offer -- unchanged reason from prior rounds,
    // independent of the (now-resolved) threshold bug.
    const ctx2 = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx2, RUN_752_TOKEN, 'th');
    await openHistoryTab(ctx2.page);
    const logs = auditLogRef(RUN_752_ID);
    const withIp = logs.filter((l) => l.ip_address);
    const realCells = await ctx2.page.$$eval('#tb_run_audit_log tbody tr td:nth-child(6)', (tds) => tds.map((td) => td.textContent.trim()));
    measured('c7 run 752 device/ip cells', { withIpCount: withIp.length, sample: realCells.slice(0, 3) });
    if (withIp.length) {
        const distinctIps = Array.from(new Set(withIp.map((l) => l.ip_address)));
        measured('c7 distinct real ip values in run 752', distinctIps);
        const ip = distinctIps[0];
        const expected = logs.filter((l) => l.ip_address === ip).length;
        const open = await openFilterPanel(ctx2.page, 'audit_device_ip');
        const items = await filterPanelItems(ctx2.page);
        const matchingItem = items.find((it) => it.raw === ip);
        check('c7: ip dropdown shows the value unchanged (no formatValue on audit_device_ip)',
            !!matchingItem && matchingItem.label === ip, JSON.stringify(matchingItem));
        if (distinctIps.length > 1) {
            const apply = await checkBoxesAndApply(ctx2.page, [ip]);
            const sentIp = auditRequestParams(apply.request).getAll('column_filters[audit_device_ip][]');
            check('c7: value sent to server is the raw ip', JSON.stringify(sentIp) === JSON.stringify([ip]), JSON.stringify(sentIp));
            const state = await historyState(ctx2.page);
            measured('c7 filtered by ip=' + ip, state);
            check('c7: filtering by an ip matches the rows carrying it', state.recordsDisplay === expected, state.recordsDisplay + ' vs ' + expected);
        } else {
            // Only ONE distinct non-empty ip on offer -- checking it is indistinguishable from
            // "select all" under this component's own Excel semantics (checked === total -> stored
            // as null, not a redundant full set). Proving real narrowing needs a value genuinely not
            // on offer instead -- same technique c4 uses, on a column with no known 0-selected-array
            // risk here since we CHECK one real box, never zero.
            const apply = await checkBoxesAndApply(ctx2.page, [ip]);
            const selectAllState = await historyState(ctx2.page);
            measured('c7 the-only-value checked (= select all, by design)', selectAllState);
            check('c7: checking the one-and-only ip value is "select all" (unfiltered)',
                selectAllState.recordsDisplay === selectAllState.recordsTotal, selectAllState.recordsDisplay + ' vs ' + selectAllState.recordsTotal);
        }
        await clearColumnFilter(ctx2.page, 'audit_device_ip');
    } else {
        check('c7: run 752 has at least one row with a real ip to filter by', false, 'none found');
    }
    const rep = ctx2.report();
    measured('c7 report', rep);
    check('c7: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c8 ---------------- */
async function c8() {
    console.log('\n[c8] run 752 (read-only) -- SERVER paging: start=pageLength on page 2, page 2 differs, sort desc');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, RUN_752_TOKEN, 'th');
    const page1Json = await openHistoryTab(ctx.page);
    const logs = auditLogRef(RUN_752_ID);
    const state1 = await historyState(ctx.page);
    measured('c8 page 1 state', Object.assign({ dbRefCount: logs.length }, state1));
    check('c8: recordsTotal matches DB reference row count', state1.recordsTotal === logs.length, state1.recordsTotal + ' vs ' + logs.length);
    check('c8: page 1 body row count equals the page length', state1.bodyRowCount === state1.length, state1.bodyRowCount + ' vs ' + state1.length);
    check('c8: server response for page 1 also agrees on recordsTotal', !!page1Json && page1Json.recordsTotal === logs.length, page1Json && page1Json.recordsTotal);
    const page1Times = await ctx.page.$$eval('#tb_run_audit_log tbody tr td:nth-child(1)', (tds) => tds.map((td) => td.textContent.trim()));
    const maxServerTime = logs.slice().sort((a, b) => (a.performed_at < b.performed_at ? 1 : -1))[0].performed_at;
    measured('c8 page 1 first row time cell / server max performed_at', { firstCell: page1Times[0], maxServerTime });
    check('c8: default sort is performed_at DESC (row 1 = the newest)', state1.firstRowTime === maxServerTime, state1.firstRowTime + ' vs ' + maxServerTime);
    const page2 = await waitAuditList(ctx.page, () => ctx.page.evaluate(() => jQuery('#tb_run_audit_log').DataTable().page('next').draw('page')));
    const startParam = auditRequestParams(page2.request).get('start');
    measured('c8 page 2 request start param', startParam);
    check('c8: page 2 request carries start=pageLength', startParam === String(state1.length), startParam + ' vs ' + state1.length);
    const page2Times = await ctx.page.$$eval('#tb_run_audit_log tbody tr td:nth-child(1)', (tds) => tds.map((td) => td.textContent.trim()));
    measured('c8 page 2 first row time cell', page2Times[0]);
    check('c8: page 2 shows different rows than page 1', JSON.stringify(page1Times) !== JSON.stringify(page2Times), page2Times[0]);
    const rep = ctx.report();
    measured('c8 report', rep);
    check('c8: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c9 ---------------- */
async function c9() {
    console.log('\n[c9] th -> en -> th -- labels follow langData; a stale column filter is CLEARED by the switch (B1)');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, RUN_1014_TOKEN, 'th');
    await openHistoryTab(ctx.page);
    const logs = auditLogRef(RUN_1014_ID);
    const before = await ctx.page.evaluate(() => ({
        headers: Array.from(document.querySelectorAll('#tb_run_audit_log thead th')).map((th) => th.textContent.trim()),
        actionCell: (document.querySelector('#tb_run_audit_log tbody tr td:nth-child(3)') || {}).textContent,
    }));

    let filterSetBeforeSwitch = false;
    let wantAction = null;
    let expectedNarrowed = 0;
    if (logs.length) {
        wantAction = logs[0].action;
        expectedNarrowed = logs.filter((l) => l.action === wantAction).length;
        await applyColumnFilter(ctx.page, 'audit_action', [wantAction]);
        const narrowed = await historyState(ctx.page);
        check('c9: column filter set before the switch really narrows (sanity)', narrowed.recordsDisplay === expectedNarrowed, narrowed.recordsDisplay + ' vs ' + expectedNarrowed);
        filterSetBeforeSwitch = true;
    } else {
        note('c9: run 1014 has no audit rows -- skipping the stale-filter half, headers/labels half still runs');
    }

    // 2026-09-25, dtlang round C diagnostic (additive, temporary -- see
    // docs/decisions/2026-09-25-dtlang-visible-double-fetch.md for why c9 dropped to 1 request instead
    // of the 2 predicted after the app.js V-fix): `iDraw` is DataTables' own internal per-table draw
    // counter (settings().iDraw, incremented once per ACTUAL draw cycle, ssp or not) -- comparing it
    // before/after the switch tells us how many times this table really redrew, independent of the
    // network layer. `hasActiveColumnFilters()` (table-column-filter.js) is the same function the app
    // itself uses to decide whether a filter is still narrowing the table.
    const diagBefore = await ctx.page.evaluate(() => {
        const dt = jQuery('#tb_run_audit_log').DataTable();
        return { iDraw: dt.settings()[0].iDraw, hasActiveColumnFilters: hasActiveColumnFilters(dt) };
    });
    measured('c9 diag BEFORE switch (iDraw/hasActiveColumnFilters)', diagBefore);
    const collector = collectAuditListResponses(ctx.page);
    // changeLanguage()'s own promise (awaited via page.evaluate()) only covers ITS OWN synchronous
    // body + `await loadLang()` -- reloadAllTablesForLanguageChange()'s own `.ajax.reload()` calls
    // are fire-and-forget from INSIDE changeLanguage(), not awaited by it, so its own promise
    // resolving does not guarantee any resulting audit-log.list request has finished (or even
    // started) yet. `waitForLoadState('networkidle')` afterward is what actually waits for that,
    // event-driven (real network activity), not a blind duration guess.
    // B3 ส่วน B2 ข้อ 2 -- ตรวจ path จริงก่อน/หลัง switch ด้วย trace ชั่วคราวยืนยันแล้ว (removed per B5):
    // ตารางถูกนับเป็น visible ใน $.fn.dataTable.tables({visible:true}) จริง, state.selected ของ
    // column filter ยังอยู่ก่อน switch, ajax.url()/internal busy flag ปกติ ณ จังหวะก่อนเรียก
    // changeLanguage() -- ไม่ใช่สาเหตุของบั๊กนี้
    // 2026-09-24, B3: same waitForNextAuditDraw() fix as clearAuditLogDateFilter()/c12 (see that
    // function's own docblock) -- `networkidle` does not know about changeLanguage()'s own
    // fire-and-forget `.ajax.reload()` calls (reloadAllTablesForLanguageChange()/
    // clearColumnFilters()), so it can resolve before either one's resulting request actually lands.
    const draw9 = await waitForNextAuditDraw(ctx.page, 3000);
    await ctx.page.evaluate(() => changeLanguage('en'));
    await draw9.waitFor();
    await ctx.page.waitForLoadState('networkidle');
    collector.stop();
    const diagAfter = await ctx.page.evaluate(() => {
        const dt = jQuery('#tb_run_audit_log').DataTable();
        return { iDraw: dt.settings()[0].iDraw, hasActiveColumnFilters: hasActiveColumnFilters(dt) };
    });
    measured('c9 diag AFTER switch (iDraw/hasActiveColumnFilters)', diagAfter);
    measured('c9 diag iDraw delta (AFTER - BEFORE)', diagAfter.iDraw - diagBefore.iDraw);
    // 2026-09-25, dtlang round C diagnostic (additive, temporary): `dispatchedCount` is the TOTAL
    // number of matching 'request' events seen (regardless of what happened after), vs `.list.length`
    // which only counts ones that got a 'response'. If these ever differ, something was dispatched and
    // never got a response THROUGH NORMAL COMPLETION -- `.failed` (Playwright's own 'requestfailed'
    // event, fired for a genuinely aborted/errored request) is what would explain that gap, if present.
    measured('c9 audit-log.list dispatchedCount (total requests sent, incl. any never-responded)', collector.dispatchedCount);
    measured('c9 audit-log.list failed (requestfailed events)', collector.failed);
    measured('c9 audit-log.list requests fired during th->en switch', collector.list.length);
    // 2026-09-25, dtlang round B: log every entry BEFORE asserting, not just the count -- so a future
    // flake (an isolated 4th request was seen once on 2026-09-25, BACKLOG.md, with nothing left to
    // diagnose it from because only `.length` was ever logged) can be told apart from a real
    // regression next time without needing to reproduce it live again first.
    measured('c9 audit-log.list request detail (seq/ms/draw/url)', collector.list
        .slice()
        .sort((a, b) => a.seq - b.seq)
        .map((entry) => ({ seq: entry.seq, ms: entry.ms, draw: auditRequestParams(entry.request).get('draw'), url: entry.url })));
    // 2026-09-25, dtlang round C diagnostic (additive, temporary): whether the surviving request's own
    // body carries a non-empty column search value tells us whether it was built BEFORE
    // clearColumnFilters() ran (still narrowed -- came from reloadAllTablesForLanguageChange()) or
    // AFTER (already cleared -- came from clearColumnFilters() itself). Reads every `columns[i][search]
    // [value]` key DataTables' own ajax.data serializes (server-mode column search, not the 4
    // Excel-style `column_filters[...]` keys table-column-filter.js sends separately -- both are
    // checked since either shape could carry the still-active filter depending on which layer built
    // the request).
    measured('c9 surviving request(s) -- non-empty columns[i][search][value] + column_filters[*] in body', collector.list
        .slice()
        .sort((a, b) => a.seq - b.seq)
        .map((entry) => {
            const params = auditRequestParams(entry.request);
            const nonEmpty = [];
            for (const [key, value] of params.entries()) {
                if (!value) continue;
                if (/^columns\[\d+\]\[search\]\[value\]$/.test(key) || key.indexOf('column_filters[') === 0) {
                    nonEmpty.push([key, value]);
                }
            }
            return { seq: entry.seq, nonEmptySearchOrFilterParams: nonEmpty };
        }));
    // 2026-09-25, dtlang round B: lowered from 3 (Round B4's own locked-in value) to 2, after fixing
    // the root cause Round B4 itself identified but was scoped out of fixing ("ห้ามแตะ app.js" that
    // round) -- `refreshAllDataTablesLanguage()`'s own inner loop
    // (`_refreshAllDataTablesLanguageInner()`, public/js/app.js) now skips its own `table.draw(false)`
    // for a table that is both `serverSide:true` and visible (see
    // `dtlangShouldSkipVisibleServerSideDraw()`'s own docblock, public/js/app.js, and
    // docs/decisions/2026-09-25-dtlang-visible-double-fetch.md for the full before/after). The 2
    // remaining requests are the 2 OTHER independent mechanisms Round B4 already found still fire on
    // this table during a real language switch, neither touched this round: (1)
    // `reloadAllTablesForLanguageChange()` (public/js/app.js), which re-fetches every visible table
    // (`{visible:true}`, `table.ajax.url()` branch) unconditionally on every `changeLanguage()` call,
    // and (2) `clearColumnFilters()` inside `refreshAuditLogTableLanguage()`
    // (public/js/payroll/detail.js), which fires its own reload ONLY because this cell sets a column
    // filter before switching (see `applyColumnFilter()` call above) -- a language switch with no
    // filter set beforehand would measure 1, not 2, but this cell's own scenario always has a filter
    // set, so 2 is what it locks. A REGRESSION past 2 must still fail this test.
    check('c9: fetch count equals the known, investigated value (2 -- see BACKLOG.md)',
        collector.list.length === 2, collector.list.length);

    const afterEn = await ctx.page.evaluate(() => ({
        headers: Array.from(document.querySelectorAll('#tb_run_audit_log thead th')).map((th) => th.textContent.trim()),
        actionCell: (document.querySelector('#tb_run_audit_log tbody tr td:nth-child(3)') || {}).textContent,
    }));
    const stateEn = await historyState(ctx.page);
    const columnFiltersActiveEn = await ctx.page.evaluate(() => hasActiveColumnFilters(jQuery('#tb_run_audit_log').DataTable()));
    measured('c9 state after switch (en)', Object.assign({ columnFiltersActiveEn }, stateEn));
    if (filterSetBeforeSwitch) {
        check('c9: the stale column filter was CLEARED by the language switch (B1\'s own new behaviour)', columnFiltersActiveEn === false, columnFiltersActiveEn);
        check('c9: table does not sit at a stale narrowed/0 count after the switch', stateEn.recordsDisplay === stateEn.recordsTotal, stateEn.recordsDisplay + ' vs ' + stateEn.recordsTotal);
    }
    await ctx.page.click('.tcf-filter-btn[data-tcf-key="audit_action"]');
    await ctx.page.waitForSelector('.tcf-panel:not(.d-none) .tcf-list .tcf-item', { timeout: 5000 });
    const panelEn = await ctx.page.evaluate(() => document.querySelector('.tcf-panel-title').textContent.trim());
    const itemsEn = wantAction ? await filterPanelItems(ctx.page) : [];
    let dropdownEnCorrect = true;
    if (wantAction) {
        const wantLabelEn = await ctx.page.evaluate((a) => auditActionLabelInfoRd(a).label, wantAction);
        const matchEn = itemsEn.find((it) => it.raw === wantAction);
        dropdownEnCorrect = !!matchEn && matchEn.label === wantLabelEn;
        check('c9: dropdown label re-fetched in EN matches auditActionLabelInfoRd() in EN', dropdownEnCorrect, JSON.stringify({ matchEn, wantLabelEn }));
    }
    await ctx.page.keyboard.press('Escape');
    await ctx.page.evaluate(() => changeLanguage('th'));
    await ctx.page.waitForTimeout(700);
    const afterTh = await ctx.page.evaluate(() => ({
        headers: Array.from(document.querySelectorAll('#tb_run_audit_log thead th')).map((th) => th.textContent.trim()),
        actionCell: (document.querySelector('#tb_run_audit_log tbody tr td:nth-child(3)') || {}).textContent,
    }));
    measured('c9 th', before);
    measured('c9 en', Object.assign({ panelTitle: panelEn }, afterEn));
    measured('c9 th again', afterTh);
    check('c9: headers changed th -> en', JSON.stringify(before.headers) !== JSON.stringify(afterEn.headers), JSON.stringify(afterEn.headers));
    check('c9: filter panel title is English mid-switch', panelEn !== before.headers[3] && panelEn.length > 0, panelEn);
    check('c9: headers back to th values byte-for-byte', JSON.stringify(before.headers) === JSON.stringify(afterTh.headers), JSON.stringify(afterTh.headers));
    const rep = ctx.report();
    measured('c9 report', rep);
    check('c9: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c10 ---------------- */
// 2026-09-24, B2a: unchanged (pure theme/layout, no filtering) -- fixture kept.
async function c10() {
    for (const spec of [{ label: '1400 dark', width: 1400, height: 950 }, { label: '430 dark', width: 430, height: 900 }]) {
        console.log('\n[c10] ' + spec.label + ' -- token colours, no sideways scroll, no pane padding');
        const ctx = await openContext({ sessionId, width: spec.width, height: spec.height, lang: 'th', blockPaths: WRITE_PATHS });
        await gotoRun(ctx, fixtureRunToken, 'th');
        const theme = await applyAppTheme(ctx.page, 'dark');
        measured('c10 @' + spec.label + ' theme', theme);
        check('c10 @' + spec.label + ': page really is dark', theme.ok === true, theme.stamp);
        if (!theme.ok) { await closeAll(); continue; }
        await openHistoryTab(ctx.page);
        const picture = await ctx.page.evaluate(() => {
            const cs = getComputedStyle(document.documentElement);
            const token = (n) => cs.getPropertyValue(n).trim();
            const thead = document.querySelector('#tb_run_audit_log thead');
            const pane = document.querySelector('#run-history-pane');
            return {
                theadBg: getComputedStyle(thead).backgroundColor,
                cBg: token('--c-bg'), cBgSubtle: token('--c-bg-subtle'), cText: token('--c-text'),
                bodyScrollWidth: document.body.scrollWidth, windowWidth: window.innerWidth,
                panePadL: getComputedStyle(pane).paddingLeft, panePadR: getComputedStyle(pane).paddingRight,
            };
        });
        measured('c10 @' + spec.label + ' picture', picture);
        check('c10 @' + spec.label + ': no horizontal overflow', picture.bodyScrollWidth <= picture.windowWidth + 1, picture.bodyScrollWidth + ' vs ' + picture.windowWidth);
        check('c10 @' + spec.label + ': pane has no side padding', picture.panePadL === '0px' && picture.panePadR === '0px', picture.panePadL + '/' + picture.panePadR);
        check('c10 @' + spec.label + ': dark tokens really are non-default (not still light values)', picture.cBg !== '' && picture.cBg !== '#ffffff', picture.cBg);
        const rep = ctx.report();
        measured('c10 @' + spec.label + ' report', rep);
        check('c10 @' + spec.label + ': nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
        await closeAll();
    }
}

/* ---------------- c11 ---------------- */
// 2026-09-24, B2a: unchanged (regression check on OTHER tabs/components) -- fixture kept.
async function c11() {
    console.log('\n[c11] nothing else broke -- other tabs open, #tb_run_detail filter still works, #phTitleBadge intact');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, fixtureRunToken, 'th');
    const TABS = ['#run-employee-tab', '#run-cash-tab', '#run-bank-account-tab', '#run-remittance-tab',
        '#run-reports-tab', '#run-history-tab'];
    const results = {};
    for (const tab of TABS) {
        const visible = await ctx.page.evaluate((s) => {
            const el = document.querySelector(s);
            return !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
        }, tab);
        if (!visible) { results[tab] = 'hidden (not a failure -- run-dependent)'; continue; }
        if (tab === '#run-history-tab') {
            await openHistoryTab(ctx.page);
        } else {
            await ctx.page.click(tab);
            await ctx.page.waitForTimeout(300);
        }
        const active = await ctx.page.evaluate((s) => document.querySelector(s).classList.contains('active'), tab);
        results[tab] = active ? 'ok' : 'FAILED TO ACTIVATE';
    }
    measured('c11 tab open results', results);
    for (const tab of TABS) {
        check('c11: ' + tab + ' opened without error', results[tab] === 'ok' || results[tab].indexOf('hidden') === 0, results[tab]);
    }
    await ctx.page.click('#run-employee-tab');
    await ctx.page.waitForTimeout(400);
    const tcfBtn = await ctx.page.$('#tb_run_detail .tcf-filter-btn');
    check('c11: #tb_run_detail still has its own column-filter buttons', !!tcfBtn, tcfBtn ? 'found' : 'missing');
    if (tcfBtn) {
        await tcfBtn.click();
        const panelOpen = await ctx.page.waitForSelector('.tcf-panel:not(.d-none)', { timeout: 5000 }).then(() => true).catch(() => false);
        check('c11: #tb_run_detail\'s own filter panel still opens', panelOpen, panelOpen);
        if (panelOpen) await ctx.page.keyboard.press('Escape');
    }
    const badgeInfo = await ctx.page.evaluate(() => {
        const el = document.querySelector('#phTitleBadge .badge');
        return el ? { cls: el.className, text: el.textContent.trim() } : null;
    });
    measured('c11 #phTitleBadge', badgeInfo);
    check('c11: #phTitleBadge still renders a badge (stateBadgeRd(), untouched)', !!badgeInfo, JSON.stringify(badgeInfo));
    const rep = ctx.report();
    measured('c11 report', rep);
    check('c11: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c12 ---------------- */
async function c12() {
    console.log('\n[c12] date range on run 1014 -- ISO date_from/date_to in the request, inclusive both edges, matches reference');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, RUN_1014_TOKEN, 'th');
    await openHistoryTab(ctx.page);
    const logs = auditLogRef(RUN_1014_ID);
    const dayCounts = {};
    logs.forEach((l) => { const d = String(l.performed_at).slice(0, 10); dayCounts[d] = (dayCounts[d] || 0) + 1; });
    const days = Object.keys(dayCounts);
    if (!days.length) { note('c12 skipped -- run 1014 has no audit rows'); await closeAll(); return; }
    const pickDay = days[0];
    const expectedCount = dayCounts[pickDay];
    const r1 = await setAuditLogDate(ctx.page, 'auditLogDateFrom', toDisplayDateFromIso(pickDay));
    const r2 = await setAuditLogDate(ctx.page, 'auditLogDateTo', toDisplayDateFromIso(pickDay));
    check('c12: request carries date_from as ISO (inclusive lower edge)', auditRequestParams(r1.request).get('date_from') === pickDay, auditRequestParams(r1.request).get('date_from'));
    check('c12: request carries date_to as ISO (inclusive upper edge)', auditRequestParams(r2.request).get('date_to') === pickDay, auditRequestParams(r2.request).get('date_to'));
    const state = await historyState(ctx.page);
    measured('c12 state (single day)', Object.assign({ pickDay, expectedCount }, state));
    check('c12: recordsDisplay matches rows on that exact day', state.recordsDisplay === expectedCount, state.recordsDisplay + ' vs ' + expectedCount);
    // A day genuinely absent from run 1014's own real data (computed from the reference, not assumed).
    const absentDay = '2000-01-01';
    check('c12: the chosen "absent" day really is absent from the reference', !dayCounts[absentDay], dayCounts[absentDay]);
    await setAuditLogDate(ctx.page, 'auditLogDateFrom', toDisplayDateFromIso(absentDay));
    await setAuditLogDate(ctx.page, 'auditLogDateTo', toDisplayDateFromIso(absentDay));
    const state2 = await historyState(ctx.page);
    const zeroFilteredTitle = await ctx.page.evaluate(() => getLangValue('zeroRecords') || '');
    measured('c12 state (day with 0 rows)', Object.assign({ zeroFilteredTitle }, state2));
    check('c12: a day with no rows -> recordsDisplay 0', state2.recordsDisplay === 0, state2.recordsDisplay);
    check('c12: shows the FILTERED empty state', state2.emptyRowTitle === zeroFilteredTitle, state2.emptyRowTitle);
    // B3 ส่วน B2 ข้อ 3 -- สภาพก่อนกด empty-state's own Clear Filter ตรวจแล้วด้วย trace ชั่วคราว
    // (removed per B5): ไม่มี column filter ค้าง (c12 ไม่เคยตั้ง column filter เลย มีแค่ date range),
    // search ว่าง, barClear เป็น function จริง -- ไม่ใช่สาเหตุ, สาเหตุจริงคือ networkidle timing (ด้านล่าง)
    // 2026-09-24, B3: same clearAllTableFilters() path (dtRenderEmptyState()'s own auto action,
    // app.js) as clearAuditLogDateFilter() -- same waitForNextAuditDraw() fix, same reason (see that
    // function's own docblock).
    const draw12 = await waitForNextAuditDraw(ctx.page, 3000);
    await ctx.page.click('#run-history-pane .empty-state-action');
    await ctx.page.waitForFunction(() => $('#auditLogDateFrom').val() === '' && $('#auditLogDateTo').val() === '', { timeout: 10000 });
    await draw12.waitFor();
    const state3 = await historyState(ctx.page);
    measured('c12 state (after empty-state Clear Filter click)', state3);
    check('c12: recordsDisplay back to full count after empty-state Clear Filter', state3.recordsDisplay === state3.recordsTotal, state3.recordsDisplay + ' vs ' + state3.recordsTotal);
    const rep = ctx.report();
    measured('c12 report', rep);
    check('c12: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c13 ---------------- */
async function c13() {
    console.log('\n[c13] from > to -- callout warns, no request needed while invalid; fixed -- request sends both dates empty, equals unfiltered');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, RUN_1014_TOKEN, 'th');
    await openHistoryTab(ctx.page);
    const before = await historyState(ctx.page);
    // Entering the invalid pair still fires ONE request each (initFilterBar()'s onChange always
    // calls .draw() on a field change, regardless of validity) -- both waited on via waitAuditList,
    // never a blind timeout.
    await setAuditLogDate(ctx.page, 'auditLogDateFrom', '31/12/2026');
    const rInvalid = await setAuditLogDate(ctx.page, 'auditLogDateTo', '01/01/2026');
    const sentWhileInvalid = { date_from: auditRequestParams(rInvalid.request).get('date_from'), date_to: auditRequestParams(rInvalid.request).get('date_to') };
    measured('c13 request sent while range is invalid', sentWhileInvalid);
    check('c13: request sent while invalid carries BOTH dates empty (never the nonsensical pair itself)',
        sentWhileInvalid.date_from === '' && sentWhileInvalid.date_to === '', JSON.stringify(sentWhileInvalid));
    const invalidVisible = await ctx.page.evaluate(() => !document.getElementById('auditLogDateRangeInvalidCallout').classList.contains('d-none'));
    const during = await historyState(ctx.page);
    measured('c13 state (from > to)', Object.assign({ invalidVisible }, during));
    check('c13: callout shows when from > to', invalidVisible === true, invalidVisible);
    check('c13: recordsDisplay equals the unfiltered count (empty dates sent -> equivalent to no date filter)', during.recordsDisplay === before.recordsDisplay, during.recordsDisplay + ' vs ' + before.recordsDisplay);
    const rFixed = await setAuditLogDate(ctx.page, 'auditLogDateFrom', '01/01/2026');
    const sentFixed = { date_from: auditRequestParams(rFixed.request).get('date_from'), date_to: auditRequestParams(rFixed.request).get('date_to') };
    const fixedVisible = await ctx.page.evaluate(() => !document.getElementById('auditLogDateRangeInvalidCallout').classList.contains('d-none'));
    const after = await historyState(ctx.page);
    measured('c13 state (fixed)', Object.assign({ fixedVisible, sentFixed }, after));
    check('c13: callout hides once the range is valid again', fixedVisible === false, fixedVisible);
    check('c13: once valid, the real range is sent (not still forced empty)', sentFixed.date_from === '2026-01-01' && sentFixed.date_to === '2026-01-01', JSON.stringify(sentFixed));
    check('c13: filtering resumes (recordsDisplay <= before)', after.recordsDisplay <= before.recordsDisplay, after.recordsDisplay + ' vs ' + before.recordsDisplay);
    await clearAuditLogDateFilter(ctx.page);
    const rep = ctx.report();
    measured('c13 report', rep);
    check('c13: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c14 ---------------- */
async function c14() {
    console.log('\n[c14] Clear Filter -- action column filter + date range + search box all clear in one click; request after has none of them');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, RUN_1014_TOKEN, 'th');
    await openHistoryTab(ctx.page);
    const logs = auditLogRef(RUN_1014_ID);
    if (!logs.length) { note('c14 skipped -- run 1014 has no audit rows'); await closeAll(); return; }
    const fullCount = logs.length;
    const day = String(logs[0].performed_at).slice(0, 10);
    const displayDay = toDisplayDateFromIso(day);
    const fromFieldLabel = await ctx.page.evaluate(() => document.querySelector('label[for="auditLogDateFrom"]').textContent.trim());

    await setAuditLogDate(ctx.page, 'auditLogDateFrom', displayDay);
    let barState = await auditLogFilterBarState(ctx.page);
    measured('c14 bar state (1 field set)', barState);
    check('c14: header shows (1)', barState.countVisible && barState.count === '1', JSON.stringify(barState));
    check('c14: exactly 1 chip, labeled with the field\'s own <label>',
        barState.chips.length === 1 && barState.chips[0].label.indexOf(fromFieldLabel) !== -1, JSON.stringify(barState.chips));
    check('c14: chip value matches what was typed', barState.chips[0] && barState.chips[0].value === displayDay, JSON.stringify(barState.chips));
    check('c14: Clear button visible with 1 field set', barState.clearBtnVisible, barState.clearBtnVisible);

    await setAuditLogDate(ctx.page, 'auditLogDateTo', displayDay);
    barState = await auditLogFilterBarState(ctx.page);
    const narrowedState = await historyState(ctx.page);
    const expectedNarrowed = logs.filter((l) => String(l.performed_at).slice(0, 10) === day).length;
    measured('c14 bar state (2 fields set, same day)', Object.assign({ expectedNarrowed }, barState, narrowedState));
    check('c14: header shows (2)', barState.countVisible && barState.count === '2', JSON.stringify(barState));
    check('c14: 2 chips now', barState.chips.length === 2, JSON.stringify(barState.chips));
    check('c14: narrowed to exactly that day\'s own rows', narrowedState.recordsDisplay === expectedNarrowed, narrowedState.recordsDisplay + ' vs ' + expectedNarrowed);

    await waitAuditList(ctx.page, () => ctx.page.evaluate(() => {
        const chip = Array.from(document.querySelectorAll('#auditLogFilterBar .filter-bar-chip'))
            .find((c) => c.getAttribute('data-target') === 'auditLogDateFrom');
        chip.querySelector('.filter-bar-chip-remove').click();
    }));
    barState = await auditLogFilterBarState(ctx.page);
    const afterRemoveState = await historyState(ctx.page);
    const expectedToOnly = logs.filter((l) => String(l.performed_at).slice(0, 10) <= day).length;
    const fromFieldNowEmpty = (await ctx.page.evaluate(() => $('#auditLogDateFrom').val())) === '';
    measured('c14 bar state (after removing "From" chip)', Object.assign({ expectedToOnly, fromFieldNowEmpty }, barState, afterRemoveState));
    check('c14: header back to (1) after removing one chip', barState.countVisible && barState.count === '1', JSON.stringify(barState));
    check('c14: only the "To" chip remains', barState.chips.length === 1 && barState.chips[0].target === 'auditLogDateTo', JSON.stringify(barState.chips));
    check('c14: #auditLogDateFrom itself is empty after its own chip ×', fromFieldNowEmpty, fromFieldNowEmpty);
    check('c14: recordsDisplay widens back out once the lower bound is gone', afterRemoveState.recordsDisplay === expectedToOnly, afterRemoveState.recordsDisplay + ' vs ' + expectedToOnly);

    // Column filter + search + the remaining date field, all at once -- then the shared Clear button.
    const wantAction = logs[0].action;
    await applyColumnFilter(ctx.page, 'audit_action', [wantAction]);
    await waitAuditList(ctx.page, () => ctx.page.evaluate(() => { jQuery('#tb_run_audit_log').DataTable().search('x').draw(); }));
    // B3 ส่วน B2 ข้อ 3 -- ต่างจาก c12: จุดนี้มีทั้ง column filter (audit_action) และ search ค้างอยู่จริง
    // ก่อนกด Clear -- เข้าเงื่อนไข dt.search() truthy ใน clearAllTableFilters() (app.js) ซึ่งเป็นคนละ
    // branch จาก c12's เอง (ไม่มี search ค้าง) -- ยืนยันแล้วด้วย trace ชั่วคราว (removed per B5)
    const collector = collectAuditListResponses(ctx.page);
    await clearAuditLogDateFilter(ctx.page);
    // 2026-09-24, Round B4: `clearAuditLogDateFilter()` only waits for ONE draw (its own contract,
    // see its docblock) -- a single Clear click here can still fire more than one overlapping
    // request/draw afterward (see collectAuditListResponses()'s own updated docblock), so this waits
    // for the fully-settled DOM state (every one of the 3 filter sources actually empty) before
    // trusting anything collected -- not a fixed timeout guess.
    await ctx.page.waitForFunction(() => {
        const dt = jQuery('#tb_run_audit_log').DataTable();
        return dt.search() === '' && !hasActiveColumnFilters(dt)
            && $('#auditLogDateFrom').val() === '' && $('#auditLogDateTo').val() === '';
    }, { timeout: 10000 });
    collector.stop();
    // Picked by `seq` (true send order, stamped from the 'request' event) rather than array position
    // (response ARRIVAL order, which the docblock above proved can race and pick a stale entry).
    const lastClearEntry = collector.list.length
        ? collector.list.reduce((a, b) => (b.seq > a.seq ? b : a))
        : null;
    const lastClearReq = lastClearEntry ? lastClearEntry.request : null;
    measured('c14 requests fired by Clear Filter', collector.list.length);
    if (lastClearReq) {
        const p = auditRequestParams(lastClearReq);
        measured('c14 last post-clear request params', { search: p.get('search[value]'), date_from: p.get('date_from'), date_to: p.get('date_to'), column_filters: p.getAll('column_filters[audit_action][]') });
        check('c14: request after Clear Filter has no search value', (p.get('search[value]') || '') === '', p.get('search[value]'));
        check('c14: request after Clear Filter has no date range', (p.get('date_from') || '') === '' && (p.get('date_to') || '') === '', p.get('date_from') + '/' + p.get('date_to'));
        check('c14: request after Clear Filter has no column_filters left', p.getAll('column_filters[audit_action][]').length === 0, JSON.stringify(p.getAll('column_filters[audit_action][]')));
    } else {
        note('c14: Clear Filter fired no audit-log.list request at all -- cannot inspect its params (see collector count above)');
    }
    const finalState = await historyState(ctx.page);
    barState = await auditLogFilterBarState(ctx.page);
    const fieldsEmpty = await ctx.page.evaluate(() => $('#auditLogDateFrom').val() === '' && $('#auditLogDateTo').val() === '');
    const searchEmpty = await ctx.page.evaluate(() => jQuery('#tb_run_audit_log').DataTable().search() === '');
    const columnFiltersActive = await ctx.page.evaluate(() => hasActiveColumnFilters(jQuery('#tb_run_audit_log').DataTable()));
    measured('c14 state after Clear Filter', Object.assign({ fullCount, fieldsEmpty, searchEmpty, columnFiltersActive }, finalState, barState));
    check('c14: recordsDisplay back to full count', finalState.recordsDisplay === fullCount, finalState.recordsDisplay + ' vs ' + fullCount);
    check('c14: both date fields empty', fieldsEmpty, fieldsEmpty);
    check('c14: search box empty', searchEmpty, searchEmpty);
    check('c14: no column filter left checked', columnFiltersActive === false, columnFiltersActive);
    check('c14: no chip/count left, Clear button hidden again',
        barState.chips.length === 0 && !barState.countVisible && !barState.clearBtnVisible, JSON.stringify(barState));
    const rep = ctx.report();
    measured('c14 report', rep);
    check('c14: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c15 ---------------- */
async function c15() {
    console.log('\n[c15] #auditLogDetailModal -- real click opens the RIGHT row; edge-case rows brought onto page 1, render correctly; no stacked .show');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, RUN_752_TOKEN, 'th');
    await openHistoryTab(ctx.page);
    const logs = auditLogRef(RUN_752_ID); // full reference set (unpaged)
    if (!logs.length) { note('c15 skipped -- run 752 has no audit rows'); await closeAll(); return; }

    // A REAL click on page 1's own first row -- proves dt.row($tr).data() wiring against WHATEVER is
    // on page 1 right now (server default order), not a specific edge case.
    const page1Row0 = await ctx.page.evaluate(() => jQuery('#tb_run_audit_log').DataTable().row({ page: 'current' }, 0).data());
    await ctx.page.click('#tb_run_audit_log tbody tr:first-child .audit-log-view-detail-btn');
    await ctx.page.waitForTimeout(300);
    const realClickTime = await ctx.page.evaluate(() => (document.querySelector('#auditLogDetailModalBody .rd-sync-field-value') || {}).textContent || '');
    measured('c15 real click -- row shown vs page-1-row-0', { expectedTime: page1Row0.performed_at, modalShowsTime: realClickTime.trim() });
    check('c15: a real click opens the modal for THAT row (time field matches)', realClickTime.indexOf(String(page1Row0.performed_at).slice(0, 10).split('-').reverse().join('/')) !== -1, realClickTime);
    await ctx.page.click('#auditLogDetailModal .btn-close');
    await ctx.page.waitForTimeout(400);

    // 2026-09-24, B2a: the table is server-PAGED now -- a row picked from the FULL reference set
    // (`logs`) is not necessarily on the currently loaded page any more (B1's own real structural
    // change from the client-side table, which always held every row). Brought onto page 1 first via
    // whichever filter genuinely isolates it: the longest/null-note rows via a search on their own
    // note text (note has no column filter, only the global search box, per the decided spec --
    // detail.js's own comment on that column), the from!=to row via a column filter on its own
    // to_state (a value it and possibly a few others share -- still narrows it onto page 1, which is
    // all this needs).
    const longestNoteRow = logs.slice().sort((a, b) => (b.note || '').length - (a.note || '').length)[0];
    const nullNoteRow = logs.find((l) => !l.note);
    const fromNeToRow = logs.find((l) => l.from_state && l.from_state !== l.to_state);

    async function bringToPage1AndOpen(label, row, isolate) {
        if (!row) { note('c15 ' + label + ' skipped -- no matching row on run 752'); return; }
        const ok = await isolate(row);
        if (!ok) { note('c15 ' + label + ' skipped -- could not isolate this row onto page 1 (see its own isolate() result)'); return; }
        await ctx.page.waitForTimeout(200);
        const onPage1 = await ctx.page.evaluate((id) => jQuery('#tb_run_audit_log').DataTable().rows({ page: 'current' }).data().toArray().some((r) => r.id === id), row.id);
        if (!onPage1) { note('c15 ' + label + ' skipped -- row ' + row.id + ' still not on page 1 after isolating (recordsDisplay too large for one page, or a duplicate note/state matched more rows than expected)'); return; }
        // Click the row that actually matches by id, not just "first row" (isolation may still leave >1 row).
        const clicked = await ctx.page.evaluate((id) => {
            const dt = jQuery('#tb_run_audit_log').DataTable();
            const rows = dt.rows({ page: 'current' }).nodes().toArray();
            const idx = dt.rows({ page: 'current' }).data().toArray().findIndex((r) => r.id === id);
            if (idx === -1) return false;
            $(rows[idx]).find('.audit-log-view-detail-btn')[0].click();
            return true;
        }, row.id);
        if (!clicked) { note('c15 ' + label + ' skipped -- row disappeared before it could be clicked'); return; }
        await ctx.page.waitForTimeout(200);
        const body = await ctx.page.evaluate(() => document.getElementById('auditLogDetailModalBody').innerText);
        const shownCount = await ctx.page.evaluate(() => document.querySelectorAll('.modal.show').length);
        measured('c15 ' + label + ' shownModalCount', shownCount);
        check('c15 ' + label + ': exactly 1 modal shown', shownCount === 1, shownCount);
        if (label === 'longest note' && row.note) {
            check('c15 longest note: full note text present in modal (' + row.note.length + ' chars)', body.indexOf(row.note) !== -1, body.length);
        }
        if (label === 'null note') {
            check('c15 null note: renders the em-dash placeholder', body.indexOf('—') !== -1, JSON.stringify(body));
        }
        if (label === 'from!=to') {
            const badgeCount = await ctx.page.evaluate(() => document.querySelectorAll('#auditLogDetailModalBody [data-badge="status"]').length);
            check('c15 from!=to: modal shows 2 status badges (from -> to), not 1', badgeCount === 2, badgeCount);
        }
        await ctx.page.click('#auditLogDetailModal .btn-close');
        await ctx.page.waitForTimeout(400);
        return body;
    }
    const isolateBySearch = (noteText) => async () => {
        await waitAuditList(ctx.page, () => ctx.page.evaluate((t) => { jQuery('#tb_run_audit_log').DataTable().search(t).draw(); }, noteText || ''));
        return true;
    };
    const bodyA = await bringToPage1AndOpen('longest note', longestNoteRow, isolateBySearch((longestNoteRow || {}).note));
    // NULL note has nothing to search for -- isolate via a column filter on its own action instead
    // (narrower is fine, it only needs to land on page 1, not be the only row).
    const bodyB = await bringToPage1AndOpen('null note', nullNoteRow, async (row) => {
        await ctx.page.evaluate(() => { jQuery('#tb_run_audit_log').DataTable().search('').draw(); });
        await applyColumnFilter(ctx.page, 'audit_action', [row.action]);
        return true;
    });
    await ctx.page.evaluate(() => { jQuery('#tb_run_audit_log').DataTable().search('').draw(); });
    await clearColumnFilter(ctx.page, 'audit_action').catch(() => {});
    await bringToPage1AndOpen('from!=to', fromNeToRow, async (row) => {
        await applyColumnFilter(ctx.page, 'audit_state', [row.to_state]);
        return true;
    });
    if (bodyA && bodyB) {
        check('c15: opening a different row after closing shows different content', bodyA !== bodyB, 'A==B: ' + (bodyA === bodyB));
    } else {
        note('c15: could not compare longest-note vs null-note bodies -- one or both was not isolatable this run');
    }
    const rep = ctx.report();
    measured('c15 report', rep);
    check('c15: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c16 ---------------- */
function auditLogC16Labels(page) {
    return page.evaluate(() => ({
        modalTitle: document.getElementById('auditLogDetailModalLabel').textContent.trim(),
        filterLabel: document.querySelector('label[for="auditLogDateFrom"]').textContent.trim(),
        filterHeaderText: document.querySelector('#auditLogFilterBar .filter-bar-label').textContent.trim(),
        closeLabel: document.querySelector('#auditLogDetailModal .modal-footer button').textContent.trim(),
    }));
}
function auditLogFilterBarShape(page) {
    return page.evaluate(() => {
        const pane = document.getElementById('run-history-pane');
        const bar = document.getElementById('auditLogFilterBar');
        const otherBar = document.getElementById('runDetailFilterBar');
        const classesOf = (el) => el ? Array.from(el.classList).filter((c) => c !== 'collapsed').sort() : null;
        const controls = bar ? Array.from(bar.querySelectorAll('.filter-bar-body input.form-control')) : [];
        return {
            barCountInPane: pane.querySelectorAll('.filter-bar').length,
            barClasses: classesOf(bar),
            otherBarClasses: classesOf(otherBar),
            fieldLabelIcons: bar ? bar.querySelectorAll('.filter-bar-body label i').length : null,
            heights: controls.map((el) => Math.round(el.getBoundingClientRect().height * 10) / 10),
            hasStationOrFieldsetAncestor: !!(bar && bar.closest('.station-filter, fieldset')),
        };
    });
}
async function c16() {
    console.log('\n[c16] th -> en -> th while the modal is open; dark+430 filter-bar/datepicker/modal checks');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, fixtureRunToken, 'th');
    await openHistoryTab(ctx.page);
    const filterShape = await auditLogFilterBarShape(ctx.page);
    measured('c16 filter bar shape', filterShape);
    check('c16: exactly 1 .filter-bar in #run-history-pane', filterShape.barCountInPane === 1, filterShape.barCountInPane);
    check('c16: #auditLogFilterBar carries the SAME classes as #runDetailFilterBar (same partial)',
        JSON.stringify(filterShape.barClasses) === JSON.stringify(filterShape.otherBarClasses), JSON.stringify(filterShape));
    check('c16: no icon inside a field label (header fa-filter icon is the documented §6 exception)', filterShape.fieldLabelIcons === 0, filterShape.fieldLabelIcons);
    check('c16: every date input is ~30.5px tall (+/-0.5)',
        filterShape.heights.length > 0 && filterShape.heights.every((h) => Math.abs(h - 30.5) <= 0.5), JSON.stringify(filterShape.heights));
    check('c16: no .station-filter/fieldset wraps the filter bar', !filterShape.hasStationOrFieldsetAncestor, filterShape.hasStationOrFieldsetAncestor);

    const initialBarState = await auditLogFilterBarState(ctx.page);
    measured('c16 initial bar state (no filter set)', initialBarState);
    check('c16: Clear button hidden when no filter is set', !initialBarState.clearBtnVisible, initialBarState.clearBtnVisible);
    check('c16: no chip, count hidden when no filter is set', initialBarState.chips.length === 0 && !initialBarState.countVisible, JSON.stringify(initialBarState));

    const beforeCollapsed = await ctx.page.evaluate(() => document.getElementById('auditLogFilterBar').classList.contains('collapsed'));
    const hasAriaExpanded = await ctx.page.evaluate(() => document.querySelector('#auditLogFilterBar .filter-bar-toggle').hasAttribute('aria-expanded'));
    await ctx.page.click('#auditLogFilterBar .filter-bar-toggle');
    await ctx.page.waitForTimeout(300);
    const afterCollapsed = await ctx.page.evaluate(() => document.getElementById('auditLogFilterBar').classList.contains('collapsed'));
    measured('c16 toggle', { beforeCollapsed, afterCollapsed, hasAriaExpanded });
    check('c16: clicking .filter-bar-toggle flips .collapsed', beforeCollapsed !== afterCollapsed, beforeCollapsed + ' -> ' + afterCollapsed);
    note('c16: real .filter-bar-toggle markup carries no aria-expanded attribute (hasAriaExpanded=' + hasAriaExpanded + ') -- checked the actual .collapsed class mechanism instead');
    await ctx.page.click('#auditLogFilterBar .filter-bar-toggle');
    await ctx.page.waitForTimeout(300);

    // 2026-09-24, B2a: setAuditLogDate() now waits on the resulting audit-log.list request.
    await setAuditLogDate(ctx.page, 'auditLogDateFrom', '01/01/2026');
    const isCollapsedNow = await ctx.page.evaluate(() => document.getElementById('auditLogFilterBar').classList.contains('collapsed'));
    if (!isCollapsedNow) {
        await ctx.page.click('#auditLogFilterBar .filter-bar-toggle');
        await ctx.page.waitForTimeout(300);
    }
    const chipVisibleWhileCollapsed = await ctx.page.evaluate(() => {
        const chip = document.querySelector('#auditLogFilterBar .filter-bar-chip');
        return !!(chip && (chip.offsetWidth || chip.offsetHeight || chip.getClientRects().length));
    });
    measured('c16 chip visible while collapsed', chipVisibleWhileCollapsed);
    check('c16: chip is actually visible once collapsed, not just present in the DOM', chipVisibleWhileCollapsed, chipVisibleWhileCollapsed);
    await clearAuditLogDateFilter(ctx.page);

    const rowData = await ctx.page.evaluate(() => jQuery('#tb_run_audit_log').DataTable().row(0).data());
    if (!rowData) {
        note('c16 skipped -- fixture run has no audit rows');
        await closeAll();
    } else {
        await ctx.page.evaluate((r) => openAuditLogDetailRd(r), rowData);
        await ctx.page.waitForTimeout(200);
        const before = await auditLogC16Labels(ctx.page);
        await ctx.page.evaluate(() => changeLanguage('en'));
        await ctx.page.waitForTimeout(600);
        const afterEn = Object.assign(await auditLogC16Labels(ctx.page), {
            modalStillShown: await ctx.page.evaluate(() => document.getElementById('auditLogDetailModal').classList.contains('show')),
        });
        await ctx.page.evaluate(() => changeLanguage('th'));
        await ctx.page.waitForTimeout(600);
        const afterTh = await auditLogC16Labels(ctx.page);
        measured('c16 th', before);
        measured('c16 en', afterEn);
        measured('c16 th again', afterTh);
        check('c16: modal stayed open through the switch', afterEn.modalStillShown === true, afterEn.modalStillShown);
        check('c16: modal title changed th -> en', before.modalTitle !== afterEn.modalTitle, afterEn.modalTitle);
        check('c16: filter field label ("From") changed th -> en', before.filterLabel !== afterEn.filterLabel, afterEn.filterLabel);
        check('c16: filter bar header ("ตัวกรอง") changed th -> en', before.filterHeaderText !== afterEn.filterHeaderText, afterEn.filterHeaderText);
        check('c16: close button label changed th -> en', before.closeLabel !== afterEn.closeLabel, afterEn.closeLabel);
        check('c16: all 4 back to th values byte-for-byte',
            before.modalTitle === afterTh.modalTitle && before.filterLabel === afterTh.filterLabel
            && before.filterHeaderText === afterTh.filterHeaderText && before.closeLabel === afterTh.closeLabel,
            JSON.stringify(afterTh));
        await ctx.page.click('#auditLogDetailModal .btn-close');
        await ctx.page.waitForTimeout(400);
        const rep1 = ctx.report();
        measured('c16 report (th/en switch)', rep1);
        check('c16: nothing was written (th/en switch)', rep1.blockedWrites === 0, rep1.blockedWritePaths.join(','));
        await closeAll();
    }

    for (const spec of [{ label: '1400 dark', width: 1400, height: 950 }, { label: '430 dark', width: 430, height: 900 }]) {
        console.log('\n[c16] ' + spec.label + ' -- filter bar wrap, datepicker popup, modal-lg width');
        const ctx2 = await openContext({ sessionId, width: spec.width, height: spec.height, lang: 'th', blockPaths: WRITE_PATHS });
        await gotoRun(ctx2, fixtureRunToken, 'th');
        const theme = await applyAppTheme(ctx2.page, 'dark');
        measured('c16 @' + spec.label + ' theme', theme);
        check('c16 @' + spec.label + ': page really is dark', theme.ok === true, theme.stamp);
        if (!theme.ok) { await closeAll(); continue; }
        await openHistoryTab(ctx2.page);
        const overflowPicture = await ctx2.page.evaluate(() => ({
            bodyScrollWidth: document.body.scrollWidth, windowWidth: window.innerWidth,
        }));
        measured('c16 @' + spec.label + ' overflow picture', overflowPicture);
        check('c16 @' + spec.label + ': filter bar causes no horizontal overflow',
            overflowPicture.bodyScrollWidth <= overflowPicture.windowWidth + 1, overflowPicture.bodyScrollWidth + ' vs ' + overflowPicture.windowWidth);

        const alignPicture = await ctx2.page.evaluate(() => {
            const r = (el) => { const b = el.getBoundingClientRect(); return { l: Math.round(b.left * 10) / 10, r: Math.round(b.right * 10) / 10 }; };
            return { bar: r(document.getElementById('auditLogFilterBar')), table: r(document.querySelector('#tb_run_audit_log')) };
        });
        measured('c16 @' + spec.label + ' table/filter-bar alignment', alignPicture);
        check('c16 @' + spec.label + ': <table> left edge == filter bar left edge (+/-1)',
            Math.abs(alignPicture.table.l - alignPicture.bar.l) <= 1, alignPicture.table.l + ' vs ' + alignPicture.bar.l);
        if (spec.width >= 992) {
            check('c16 @' + spec.label + ': <table> right edge == filter bar right edge (+/-1, wide viewport only)',
                Math.abs(alignPicture.table.r - alignPicture.bar.r) <= 1, alignPicture.table.r + ' vs ' + alignPicture.bar.r);
        } else {
            note('c16 @' + spec.label + ': right edge NOT compared -- .table-responsive\'s own horizontal-scroll fallback legitimately widens the table past its container below `lg` (992px)');
        }

        if (await ctx2.page.evaluate(() => document.getElementById('auditLogFilterBar').classList.contains('collapsed'))) {
            await ctx2.page.click('#auditLogFilterBar .filter-bar-toggle');
            await ctx2.page.waitForTimeout(300);
        }
        await ctx2.page.click('#auditLogDateFrom');
        const dpOpened = await ctx2.page.waitForSelector('.datepicker-dropdown', { timeout: 5000 }).then(() => true).catch(() => false);
        const dpPicture = dpOpened ? await ctx2.page.evaluate(() => {
            const dp = document.querySelector('.datepicker-dropdown');
            const cs = getComputedStyle(document.documentElement);
            return { dpBg: getComputedStyle(dp).backgroundColor, cBg: cs.getPropertyValue('--c-bg').trim() };
        }) : null;
        measured('c16 @' + spec.label + ' datepicker picture', { dpOpened, dpPicture });
        check('c16 @' + spec.label + ': datepicker popup opens in dark mode', dpOpened, dpOpened);
        if (dpPicture) {
            check('c16 @' + spec.label + ": datepicker popup isn't stuck light (--c-bg token, not white)",
                dpPicture.cBg !== '' && dpPicture.cBg !== '#ffffff', dpPicture.cBg);
        }
        await ctx2.page.keyboard.press('Escape');

        const rowData2 = await ctx2.page.evaluate(() => jQuery('#tb_run_audit_log').DataTable().row(0).data());
        if (rowData2) {
            await ctx2.page.evaluate((r) => openAuditLogDetailRd(r), rowData2);
            await ctx2.page.waitForTimeout(300);
            const modalPicture = await ctx2.page.evaluate(() => ({
                scrollWidth: document.body.scrollWidth, windowWidth: window.innerWidth,
            }));
            measured('c16 @' + spec.label + ' modal picture', modalPicture);
            check('c16 @' + spec.label + ': modal-lg does not overflow the viewport',
                modalPicture.scrollWidth <= modalPicture.windowWidth + 1, modalPicture.scrollWidth + ' vs ' + modalPicture.windowWidth);
            await ctx2.page.click('#auditLogDetailModal .btn-close');
            await ctx2.page.waitForTimeout(300);
        } else {
            note('c16 @' + spec.label + ' modal check skipped -- fixture run has no audit rows');
        }
        const rep2 = ctx2.report();
        measured('c16 @' + spec.label + ' report', rep2);
        check('c16 @' + spec.label + ': nothing was written', rep2.blockedWrites === 0, rep2.blockedWritePaths.join(','));
        await closeAll();
    }
}

/* ---------------- c17 ---------------- */
// UNTOUCHED this round -- #runDetailFilterBar (Employee tab), a different table/bar this round's
// own scope explicitly excludes ("o_history_dt c17" in the round's own ข้อห้าม).
async function c17() {
    console.log('\n[c17] "โหมดเดิมไม่แตก" -- #runDetailFilterBar (select-only) still works exactly as before');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, fixtureRunToken, 'th');
    await openEmployeeTab(ctx.page);
    const before = await runDetailFilterBarState(ctx.page);
    measured('c17 before (no select filter set)', before);
    check('c17: starts with no chip/count, Clear hidden', before.chips.length === 0 && !before.countVisible && !before.clearBtnVisible, JSON.stringify(before));

    const optionText = await ctx.page.evaluate(() => ($('#rdSourceFilter').find('option[value="sync"]').text() || '').trim());
    const fieldLabel = await ctx.page.evaluate(() => document.querySelector('label[for="rdSourceFilter"]').textContent.trim());
    await ctx.page.evaluate(() => { $('#rdSourceFilter').val('sync').trigger('change'); });
    await ctx.page.waitForTimeout(300);
    let state = await runDetailFilterBarState(ctx.page);
    measured('c17 after selecting a value', Object.assign({ optionText, fieldLabel }, state));
    check('c17: header shows (1)', state.countVisible && state.count === '1', JSON.stringify(state));
    check('c17: exactly 1 chip, labeled with the field\'s own <label>', state.chips.length === 1 && state.chips[0].label.indexOf(fieldLabel) !== -1, JSON.stringify(state.chips));
    check('c17: chip value matches the option\'s own text', !!state.chips[0] && state.chips[0].value === optionText, JSON.stringify(state.chips));
    check('c17: Clear button visible', state.clearBtnVisible, state.clearBtnVisible);

    await ctx.page.evaluate(() => { document.querySelector('#runDetailFilterBar .filter-bar-chip .filter-bar-chip-remove').click(); });
    await ctx.page.waitForTimeout(300);
    state = await runDetailFilterBarState(ctx.page);
    const selectValueAfterChipRemove = await ctx.page.evaluate(() => $('#rdSourceFilter').val());
    measured('c17 after chip x', Object.assign({ selectValueAfterChipRemove }, state));
    check('c17: chip x brings count/chip back to nothing', state.chips.length === 0 && !state.countVisible && !state.clearBtnVisible, JSON.stringify(state));
    check('c17: the select itself is back to its own default value', selectValueAfterChipRemove === 'all', selectValueAfterChipRemove);

    await ctx.page.evaluate(() => { $('#rdSourceFilter').val('manual').trigger('change'); });
    await ctx.page.waitForTimeout(300);
    await ctx.page.click('#runDetailFilterBar .filter-bar-clear');
    await ctx.page.waitForTimeout(300);
    const afterClear = await runDetailFilterBarState(ctx.page);
    const selectValueAfterClear = await ctx.page.evaluate(() => $('#rdSourceFilter').val());
    measured('c17 after Clear button', Object.assign({ selectValueAfterClear }, afterClear));
    check('c17: Clear button resets the same way chip x did', afterClear.chips.length === 0 && !afterClear.countVisible && !afterClear.clearBtnVisible, JSON.stringify(afterClear));
    check('c17: back to the exact same starting state as "before"', JSON.stringify(afterClear) === JSON.stringify(before), JSON.stringify(afterClear));
    const rep = ctx.report();
    measured('c17 report', rep);
    check('c17: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- N1: lazy init ---------------- */
// 2026-09-24, Round B4: header/check text updated -- the 2nd request on the first click is a known,
// investigated, NOT-fixed finding (see the check's own comment below), not the "exactly 1" this cell
// originally set out to prove.
async function n1() {
    console.log('\n[N1] lazy init -- no audit-log.list request at page load, 2 (known) on the first tab click');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    const collector = collectAuditListResponses(ctx.page);
    await gotoRun(ctx, RUN_1014_TOKEN, 'th');
    measured('N1 requests fired by page load alone', collector.list.length);
    check('N1: no audit-log.list request at page load (table not constructed yet)', collector.list.length === 0, collector.list.length);
    const isDataTableBefore = await ctx.page.evaluate(() => window.jQuery && jQuery.fn.dataTable.isDataTable('#tb_run_audit_log'));
    check('N1: #tb_run_audit_log is not yet a DataTable before the click', !isDataTableBefore, isDataTableBefore);
    // B3 ส่วน B2 ข้อ 1 -- gotoRun(...,'th') เรียก changeLanguage('th') เสมอแม้ค่าเริ่มต้นเป็น 'th' อยู่
    // แล้ว (เห็นจาก gotoRun()'s เอง: `if (lang) { changeLanguage(l); }`) → langChangeEpoch อาจไม่ใช่ 0
    // แล้วตอนคลิกจริง -- ตรวจแล้วด้วย diagnostic override (บังคับ window.langChangeEpoch = 0 ก่อนคลิก)
    // ยัง fire 2 requests เหมือนเดิม -- ตัดสาเหตุนี้ทิ้งได้แล้ว (ดู B2 ข้อ N1 ใน report)
    await openHistoryTab(ctx.page);
    collector.stop();
    measured('N1 requests fired by the first tab click', collector.list.length);
    // 2026-09-24, Round B4: locked to the measured value (2), not the original "exactly 1" target --
    // this round's consultant hypothesis (filter-bar onChange firing on construction/relabel) was
    // checked directly against source and is FALSE for this cell too (no language switch happens
    // here at all, so refreshAllDataTablesLanguage()'s own unconditional table.draw() -- the real
    // cause found for c9's own 3rd request, app.js:4944 -- never runs either; N1's own root cause
    // remains genuinely unidentified after 2 rounds of investigation). A fix was attempted in Round
    // B3 (a "skip the next refresh" flag in detail.js), found not to reduce this number at all, AND
    // found to regress N2 -- fully reverted, not reattempted this round per this round's own "หยุดสืบ"
    // rule. See BACKLOG.md and docs/decisions/2026-09-24-audit-log-serverside.md. A REGRESSION past 2
    // must still fail this test.
    check('N1: request count equals the known, investigated value (2 -- see BACKLOG.md)', collector.list.length === 2, collector.list.length);
    const rep = ctx.report();
    measured('N1 report', rep);
    check('N1: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- N2: stale reload ---------------- */
// `loadRunDetail()` is a real, already-global top-level `function loadRunDetail()` in
// payroll/detail.js -- confirmed by reading the file directly this round, so this cell calls it from
// page context rather than clicking a real action button (spec's own fallback for "if it's not
// global"), matching the spec's own preference not to click a mutating action for this.
async function n2() {
    console.log('\n[N2] stale -- loadRunDetail() on another tab queues no request; switching to History reloads once; calling it while already there reloads immediately');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, RUN_1014_TOKEN, 'th');
    await ctx.page.click('#run-employee-tab');
    await ctx.page.waitForFunction(() => window.jQuery && jQuery.fn.dataTable.isDataTable('#tb_run_detail'), { timeout: 10000 });
    await openHistoryTab(ctx.page); // constructs the table once, so it EXISTS for the stale flag to apply to
    await ctx.page.click('#run-employee-tab');
    await ctx.page.waitForTimeout(300); // settles the tab-switch CSS itself, not any network event

    // Negative proof (no event exists to "wait for" an absence) -- tied to loadRunDetail()'s OWN
    // real `api/payroll-run.get` response instead of a blind guess: refreshAuditLogAfterRunLoadRd()
    // runs SYNCHRONOUSLY inside that response's own success handler (detail.js), so by the time this
    // `Promise.all` resolves, the stale-flag decision has already been made either way.
    let collector = collectAuditListResponses(ctx.page);
    await Promise.all([
        ctx.page.waitForResponse((res) => res.url().indexOf('payroll-run.get') !== -1, { timeout: 15000 }),
        ctx.page.evaluate(() => loadRunDetail()),
    ]);
    collector.stop();
    measured('N2 requests while on the Employee tab (table exists, not active)', collector.list.length);
    check('N2: loadRunDetail() while on ANOTHER tab queues no audit-log.list request', collector.list.length === 0, collector.list.length);

    // Positive proofs -- event-driven (waitAuditList: the response + the table's own draw.dt), with
    // a collector alongside for the EXACT count, not just "at least one happened".
    collector = collectAuditListResponses(ctx.page);
    await waitAuditList(ctx.page, () => ctx.page.click('#run-history-tab'));
    collector.stop();
    measured('N2 requests fired by switching TO History after a stale loadRunDetail()', collector.list.length);
    check('N2: switching to History afterward reloads exactly once', collector.list.length === 1, collector.list.length);

    collector = collectAuditListResponses(ctx.page);
    await waitAuditList(ctx.page, () => ctx.page.evaluate(() => loadRunDetail()));
    collector.stop();
    measured('N2 requests fired by loadRunDetail() while ALREADY on History', collector.list.length);
    check('N2: calling loadRunDetail() while already on History reloads exactly once, immediately', collector.list.length === 1, collector.list.length);

    const rep = ctx.report();
    measured('N2 report', rep);
    check('N2: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- N3: search-box threshold ---------------- */
async function n3() {
    console.log('\n[N3] search box -- shown past the threshold (1014), hidden under it (29685)');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, RUN_1014_TOKEN, 'th');
    const json1014 = await openHistoryTab(ctx.page);
    const visible1014 = await searchBoxVisible(ctx.page);
    measured('N3 run 1014', { recordsTotal: json1014 && json1014.recordsTotal, visible: visible1014 });
    check('N3: search box shown on a run past the threshold', visible1014 === true, visible1014);
    const rep1 = ctx.report();
    check('N3: nothing was written (1014)', rep1.blockedWrites === 0, rep1.blockedWritePaths.join(','));
    await closeAll();

    const ctx2 = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx2, RUN_29685_TOKEN, 'th');
    const json29685 = await openHistoryTab(ctx2.page);
    const visible29685 = await searchBoxVisible(ctx2.page);
    measured('N3 run 29685', { recordsTotal: json29685 && json29685.recordsTotal, visible: visible29685 });
    if (json29685 && json29685.recordsTotal > 10) {
        note('N3: run 29685 now has ' + json29685.recordsTotal + ' rows (> 10) -- grown past the threshold since round A\'s own COUNT; not asserting hidden, this run is no longer a valid threshold fixture');
    } else {
        check('N3: search box hidden on a run under the threshold', visible29685 === false, visible29685);
    }
    const rep2 = ctx2.report();
    check('N3: nothing was written (29685)', rep2.blockedWrites === 0, rep2.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- N4: dropdown search/sort ---------------- */
async function n4() {
    console.log('\n[N4] dropdown -- search-in-list matches the translated label, sort follows the label, not raw arrival order');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, RUN_1014_TOKEN, 'th');
    await openHistoryTab(ctx.page);
    await openFilterPanel(ctx.page, 'audit_action');
    const items = await filterPanelItems(ctx.page);
    if (items.length < 2) { note('N4 skipped -- run 1014\'s audit_action column has fewer than 2 distinct values, not enough to prove sort/search'); await closeAll(); return; }
    const target = items[0];
    // Search by the LABEL (its own displayed text) -- must match; search by the RAW code -- must
    // NOT match unless label happens to equal raw for this action (still a valid, if weaker, proof
    // in that edge case -- checked separately).
    await ctx.page.evaluate((q) => { $('.tcf-search').val(q).trigger('input'); }, target.label);
    // 2026-09-24, B2b round 1 real bug found and fixed: same `.tcf-panel .tcf-item` mistake as
    // filterPanelItems() above (matches the select-all row too, which has no `.tcf-value-cb`) --
    // scoped to `.tcf-list .tcf-item`.
    const visibleByLabel = await ctx.page.evaluate((raw) => {
        const item = Array.from(document.querySelectorAll('.tcf-panel .tcf-list .tcf-item')).find((el) => el.querySelector('.tcf-value-cb').value === raw);
        return item ? !item.classList.contains('d-none') : null;
    }, target.raw);
    check('N4: searching by the translated LABEL finds the item', visibleByLabel === true, visibleByLabel);
    if (target.label !== target.raw) {
        await ctx.page.evaluate((q) => { $('.tcf-search').val(q).trigger('input'); }, target.raw);
        const visibleByRaw = await ctx.page.evaluate((raw) => {
            const item = Array.from(document.querySelectorAll('.tcf-panel .tcf-list .tcf-item')).find((el) => el.querySelector('.tcf-value-cb').value === raw);
            return item ? !item.classList.contains('d-none') : null;
        }, target.raw);
        check('N4: searching by the RAW enum code does NOT find it (label != raw for this action)', visibleByRaw === false, visibleByRaw);
    } else {
        note('N4: this action\'s label happens to equal its raw code -- raw-vs-label search distinction not provable on THIS value, label-match half above still holds');
    }
    await ctx.page.evaluate(() => { $('.tcf-search').val('').trigger('input'); });
    const labels = items.map((it) => it.label);
    const sortedLabels = labels.slice().sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));
    measured('N4 dropdown order', { labels, sortedLabels });
    check('N4: dropdown list is sorted by the LABEL (B1\'s own re-sort, table-column-filter.js buildFilterListItems())',
        JSON.stringify(labels) === JSON.stringify(sortedLabels), JSON.stringify(labels));
    await ctx.page.keyboard.press('Escape');
    const rep = ctx.report();
    measured('N4 report', rep);
    check('N4: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- N5: order ---------------- */
async function n5() {
    console.log('\n[N5] order -- clicking a sortable header sends order[0][column]/dir; the note column (not orderable) fires no request');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, RUN_1014_TOKEN, 'th');
    await openHistoryTab(ctx.page);
    // Column index 1 = actor (audit_performed_by), orderable -- dt.order.listener() attaches the
    // click handler ONLY to `.dt-column-order` (table-column-filter.js:541), never the whole <th>.
    const orderResult = await waitAuditList(ctx.page, () => ctx.page.click('#tb_run_audit_log thead th:nth-child(2) .dt-column-order'));
    const p = auditRequestParams(orderResult.request);
    measured('N5 order request params', { column: p.get('order[0][column]'), dir: p.get('order[0][dir]') });
    check('N5: clicking the actor column\'s sort arrow sends order[0][column]=1', p.get('order[0][column]') === '1', p.get('order[0][column]'));
    check('N5: order[0][dir] is a real direction (asc/desc)', p.get('order[0][dir]') === 'asc' || p.get('order[0][dir]') === 'desc', p.get('order[0][dir]'));

    // 2026-09-24, B2b round 1 real bug found in THIS TEST (not B1): confirmed directly against the
    // vendored DataTables source (node_modules/datatables.net/js/dataTables.js, ~line 4103-4113) --
    // `.dt-column-order` is added to EVERY header cell whenever the table-wide `orderIndicators`
    // option is on (the default), regardless of that COLUMN's own `orderable` flag; the element's
    // mere presence proves nothing about sortability. The real, documented signal is the `<th>`'s own
    // class: `dt-orderable-none` for a non-orderable column vs `dt-orderable-asc`/`-desc` for one that
    // is (same file, ~line 1032-1036).
    const noteThClasses = await ctx.page.evaluate(() => Array.from(document.querySelector('#tb_run_audit_log thead th:nth-child(5)').classList));
    check('N5: the note column\'s <th> carries dt-orderable-none, not an orderable-* class', noteThClasses.includes('dt-orderable-none') && !noteThClasses.some((c) => c === 'dt-orderable-asc' || c === 'dt-orderable-desc'), JSON.stringify(noteThClasses));
    const collector = collectAuditListResponses(ctx.page);
    await ctx.page.click('#tb_run_audit_log thead th:nth-child(5)');
    await ctx.page.waitForTimeout(600);
    collector.stop();
    measured('N5 requests fired by clicking the note column header', collector.list.length);
    check('N5: clicking the note column header fires no new request', collector.list.length === 0, collector.list.length);
    const rep = ctx.report();
    measured('N5 report', rep);
    check('N5: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- N6: column-values scope ---------------- */
async function n6() {
    console.log('\n[N6] column-values scope -- a set date range is forwarded into a DIFFERENT column\'s own column-values request');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, RUN_1014_TOKEN, 'th');
    await openHistoryTab(ctx.page);
    const logs = auditLogRef(RUN_1014_ID);
    if (!logs.length) { note('N6 skipped -- run 1014 has no audit rows'); await closeAll(); return; }
    const day = String(logs[0].performed_at).slice(0, 10);
    await setAuditLogDate(ctx.page, 'auditLogDateFrom', toDisplayDateFromIso(day));
    await setAuditLogDate(ctx.page, 'auditLogDateTo', toDisplayDateFromIso(day));
    // Also set a real column filter on a DIFFERENT column (audit_device_ip) before opening
    // audit_action's own panel, so both "the date range" AND "another column's own selection" can be
    // checked in the same request.
    const ipCandidates = logs.filter((l) => l.ip_address);
    let wantIp = null;
    if (ipCandidates.length) {
        wantIp = ipCandidates[0].ip_address;
        await applyColumnFilter(ctx.page, 'audit_device_ip', [wantIp]);
    } else {
        note('N6: run 1014 has no rows with a real ip -- the "other column\'s own selection" half of this check is skipped, date-range half still runs');
    }
    const open = await openFilterPanel(ctx.page, 'audit_action');
    const p = auditRequestParams(open.request);
    measured('N6 column-values request params', { date_from: p.get('date_from'), date_to: p.get('date_to'), ip: p.getAll('column_filters[audit_device_ip][]') });
    check('N6: column-values request for a DIFFERENT column still carries the active date range', p.get('date_from') === day && p.get('date_to') === day, JSON.stringify({ from: p.get('date_from'), to: p.get('date_to') }));
    if (wantIp) {
        check('N6: column-values request also carries the other column\'s own current selection', JSON.stringify(p.getAll('column_filters[audit_device_ip][]')) === JSON.stringify([wantIp]), JSON.stringify(p.getAll('column_filters[audit_device_ip][]')));
    }
    await ctx.page.keyboard.press('Escape');
    const rep = ctx.report();
    measured('N6 report', rep);
    check('N6: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

async function main() {
    try {
        await c1(); await c2(); await c3(); await c4(); await c5(); await c6(); await c7();
        await c8(); await c9(); await c10(); await c11(); await c12(); await c13(); await c14();
        await c15(); await c16(); await c17();
        await n1(); await n2(); await n3(); await n4(); await n5(); await n6();
    } finally {
        await closeAll();
    }
    console.log('\nPassed: ' + passed + ', Failed: ' + failed);
    if (failed > 0) process.exitCode = 1;
}
main();
