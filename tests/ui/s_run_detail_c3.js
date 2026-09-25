/**
 * ก้อน 3 measurement (payroll/detail, 3e-3b round B1): Payroll Detail's #runDetailFilterBar after
 * มติ ข (#rdDepartmentFilter/#rdPaymentMethodFilter removed, #rdSourceFilter is the only field left)
 * + the gutter-zero CSS selector rewrite (6 per-wrapper-id rules -> one
 * `#runDetailTabsContent > .tab-pane .dt-container .row` rule, style.css). Full decision record:
 * docs/decisions/2026-09-23-chunk3-run-detail-filter-gutter.md.
 *
 * Run:  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/s_run_detail_c3.js <PHPSESSID>
 *
 * PHPSESSID comes straight out of tests/ui/mksession.php's own JSON/state-file output
 * (`STATE_FILE = tests/ui/.last-session.json`, mksession.php:69) -- same source
 * tests/ui/o_history_dt.js's own argv[2] uses (o_history_dt.js:78). round B2a item 2.6: unlike
 * o_history_dt.js, this file has no cell that opens mksession's own fixture run at all -- every run
 * either cell navigates to is a real, hardcoded dev run (752/29685/1014/1015, all resolved through
 * id_codec_cli.php the same way o_history_dt.js resolves run 752) -- so the fixtureRunToken argv[3]
 * o_history_dt.js takes has nothing to receive here and was REMOVED rather than kept unused for a
 * false consistency with that file's own signature.
 *
 * 3 cells, each its own context (same openContext()/WRITE_PATHS/gotoRun() shape as o_history_dt.js --
 * see that file's own docblock for why blockPaths exists at all and why api/payroll-run.recalculate
 * needs no extra entry here, harness.js's own RECALCULATE_PATH already blocks it unconditionally):
 *   c1  #runDetailFilterBar shape in 2 real cases -- a showDataSourceFilter=true run (#rdSourceFilter
 *       visible, exactly 1 field in the bar, no trace of the 2 removed fields) and run 29685
 *       (showDataSourceFilter=false, real incentive/include_base_salary=0 run in dev DB -- the WHOLE
 *       bar hidden, not just #rdSourceFilter's own wrap) -- both cases also check #tb_run_detail's own
 *       department/payment_method_code column-header filter buttons are still there (column filter is
 *       the replacement, not a regression). round B2a's own item 2.4: "hidden" is read from computed
 *       style (offsetParent === null), never from the `d-none` class name itself -- a class name is
 *       what the code HAPPENS to use today, not what "hidden" means; #rdSourceFilter's own value and
 *       #tb_run_detail's own recordsDisplay/recordsTotal (DataTables' own page.info()) are read too,
 *       to prove the hidden bar's stale-value reset (detail.js) really leaves the table showing every
 *       row, not silently narrowed by a value nobody can see or clear. round B2c: a 3rd real branch of
 *       the same condition (incentive run WITH include_base_salary=1) NOTEs instead of asserting --
 *       no such run exists in dev DB right now (fresh SELECT, 0 rows).
 *   c2  gutter-zero -- every `.dt-container .row` under `#runDetailTabsContent > .tab-pane` (the new
 *       selector's own match set) reads computed `--bs-gutter-x` = 0, checked per EXACT wrapper id
 *       (round B2a item 2.3: the 6 real ids by name, not just a count) -- each of the 6 tabs is
 *       existence/visibility-checked before its own click (round B2a item 2.2: a tab that isn't there
 *       NOTEs and is skipped, never left to time out the whole file). round B2c: runs on run 1014
 *       (state='locked'), not 752 -- 752's own `state` drifted to `draft` between rounds, which gates
 *       the cash/bank_account/remittance tabs' own content closed (RD_REPORT_ALLOWED_STATES,
 *       detail.js:429) and made this cell fail for reasons unrelated to the gutter selector itself.
 *       #tb_run_remittance_wrapper is excluded from the expected set ONLY when
 *       run-remittance-tab's own `<li>` is confirmed `d-none` live (remittance_count===0,
 *       updateRunDetailTabVisibility(), detail.js:1274-1283) -- true for every run in dev DB right
 *       now, but checked per-run, not hardcoded.
 *   c3  "โหมดเดิมไม่แตก" -- a DataTable on a DIFFERENT page (payroll LIST) keeps whatever its own
 *       `--bs-gutter-x` was (Bootstrap's own default, non-zero) -- proves the new selector's
 *       `#runDetailTabsContent` scope really doesn't leak; PLUS #tb_report_history (inside
 *       #reportHistoryModal, a SIBLING of every `.tab-pane` under #runDetailTabsContent, never covered
 *       by the old 6-id list either) still reads whatever gutter value it had before this round --
 *       opened via its own real trigger, `.btn-report-history` (rendered per report row,
 *       public/js/payroll/detail.js:527; its click handler opens the modal and constructs
 *       `#tb_report_history` as a real DataTable, detail.js:1087-1099) -- read-only (a click that only
 *       shows a modal is not a write; the button is skipped, never force-clicked, if it doesn't exist
 *       or is `disabled` for this run). round B2c: tries run 1014 first, then run 1015, NOTEs only if
 *       NEITHER has any downloaded-report history yet (both confirmed 0 report_export_logs rows this
 *       round -- expect a NOTE, not a PASS, until a run with real download history exists).
 *
 * Writes: none. Every context passes blockPaths: WRITE_PATHS (copied from o_history_dt.js verbatim)
 * on top of harness.js's own 2 built-in blocks (api/user-preference.save,
 * api/payroll-run.recalculate). Run 29685 is opened READ-ONLY in c1 -- no field is ever set on it (the
 * whole point of that half of c1 is that NOTHING is visible to click), no delete, nothing that could
 * mutate it.
 *
 * No dark-mode cell in this file -- the one measurement that needed it (.detail-section
 * background/border in light vs dark) was CUT this round (round B1's own item C: no token in
 * tokens.css/style.css resolves to the card's exact existing #fff/#eef0f2 pair in light mode, see
 * docs/decisions/2026-09-23-chunk3-run-detail-filter-gutter.md) -- .detail-section itself was left
 * untouched, so there is nothing color-theme-dependent for this file to measure.
 */
'use strict';
const { openContext, closeAll } = require('./harness');
const { execFileSync } = require('child_process');
const path = require('path');

const sessionId = process.argv[2];
if (!sessionId) {
    throw new Error('usage: node tests/ui/s_run_detail_c3.js <PHPSESSID>');
}

let passCount = 0;
let failCount = 0;
function check(label, ok, detail) {
    if (ok) { passCount++; console.log('  PASS  ' + label); }
    else { failCount++; console.log('  FAIL  ' + label + (detail !== undefined ? '  -- ' + JSON.stringify(detail) : '')); }
    return ok;
}
function measured(label, value) {
    console.log('  MEASURED  ' + label + ' = ' + JSON.stringify(value));
}
function note(msg) {
    console.log('  NOTE  ' + msg);
}

// Same 2-built-in-block-plus-these-extra list o_history_dt.js's own WRITE_PATHS uses (copied
// verbatim, not reduced to only the routes this file's own cells currently reach).
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

// 2026-09-23, round B1's own item 1.1 SELECT (tmp-chunk3-roundB1.php, deleted after use) confirmed
// this real run in dev DB: status=active (opens fine, not soft-deleted), state=draft,
// run_purpose='incentive', include_base_salary=0 -- the one real showDataSourceFilter=false case.
const RUN_29685_ID = 29685;
// 3e-3 round A's own fixture: real dev run, run_purpose='payroll' (showDataSourceFilter=true
// unconditionally -- see detail.js's own condition, run_purpose!=='incentive' short-circuits it true
// regardless of include_base_salary), 1571 audit rows, read-only, never mutated -- same run
// o_history_dt.js already opens read-only. Used for c1/true and c3 only.
const RUN_752_ID = 752;
// 2026-09-23, round B2c: run 752's own `state` drifted from `locked` (round A/B1's own snapshot) to
// `draft` in dev DB between rounds (shared dev data, confirmed via a fresh read-only SELECT) -- a
// `draft` run makes loadRunCashTab()/loadRunBankAccountTab()/loadRunRemittanceTab() (detail.js) all
// gate closed (RD_REPORT_ALLOWED_STATES = ['approved','paid','locked'], detail.js:429) and never
// construct their own DataTable at all, which is what made c2's own gutter check fail on run 752 in
// round B2b's first measurement -- NOT a bug in the app or this script, just the wrong run for a cell
// that needs those 3 tabs' content to actually exist. c2 uses run 1014 instead (state='locked',
// auto_recalculate=0, both reconfirmed via a fresh read-only SELECT this same round) -- NEVER use a
// `draft` run for this cell again; if 1014 itself ever drifts to `draft` too, re-SELECT for another
// real run with state IN ('approved','paid','locked') rather than reaching for 752 out of habit.
const RUN_1014_ID = 1014;
// Same shape as 1014 (state='locked', auto_recalculate=0, reconfirmed live this round) -- c3's own
// fallback when 1014 has no downloadable report history yet.
const RUN_1015_ID = 1015;

const ID_CODEC_CLI = path.join(__dirname, 'id_codec_cli.php');
function idCodecEncode(id) {
    return execFileSync('php', [ID_CODEC_CLI, 'encode', String(id)], { encoding: 'utf8' }).trim();
}
function idCodecDecode(token) {
    const out = execFileSync('php', [ID_CODEC_CLI, 'decode', token], { encoding: 'utf8' }).trim();
    return out === 'none' ? null : parseInt(out, 10);
}
function resolveRunToken(label, token, expectedId) {
    const decoded = idCodecDecode(token);
    measured('IdCodec decode: ' + label, { token: token, decoded: decoded, expected: expectedId });
    check('IdCodec: "' + label + '" token really decodes to ' + expectedId, decoded === expectedId, String(decoded));
    return token;
}

const RUN_29685_TOKEN = resolveRunToken('run 29685', idCodecEncode(RUN_29685_ID), RUN_29685_ID);
const RUN_752_TOKEN = resolveRunToken('run 752', idCodecEncode(RUN_752_ID), RUN_752_ID);
const RUN_1014_TOKEN = resolveRunToken('run 1014', idCodecEncode(RUN_1014_ID), RUN_1014_ID);
const RUN_1015_TOKEN = resolveRunToken('run 1015', idCodecEncode(RUN_1015_ID), RUN_1015_ID);

// Same navigation shape o_history_dt.js's own gotoRun() uses (tests/ui/o_history_dt.js:150-157).
async function gotoRun(ctx, token, lang) {
    await ctx.page.goto(ctx.url('/payroll-process/' + token), { waitUntil: 'networkidle' });
    await ctx.page.waitForTimeout(900);
    if (lang) {
        await ctx.page.evaluate((l) => { if (typeof changeLanguage === 'function') changeLanguage(l); }, lang);
        await ctx.page.waitForTimeout(500);
    }
}
async function openEmployeeTab(page) {
    await page.click('#run-employee-tab');
    await page.waitForFunction(() => window.jQuery && jQuery.fn.dataTable.isDataTable('#tb_run_detail'), { timeout: 10000 });
    await page.waitForTimeout(300);
}

/* ---------------- c1 ---------------- */
// round B2a item 2.4: "hidden" read via offsetParent (computed layout, true regardless of WHICH class
// or inline style the app happens to hide it with) -- not `classList.contains('d-none')`, which only
// proves the code used that one specific mechanism, not that the element is actually invisible.
function filterBarShape(page) {
    return page.evaluate(() => {
        const bar = document.getElementById('runDetailFilterBar');
        const fields = bar ? Array.from(bar.querySelectorAll('.filter-bar-body select, .filter-bar-body input.form-control')) : [];
        const sourceField = document.getElementById('rdSourceFilter');
        const dt = (window.jQuery && jQuery.fn.dataTable.isDataTable('#tb_run_detail')) ? $('#tb_run_detail').DataTable() : null;
        const pageInfo = dt ? dt.page.info() : null;
        return {
            barExistsInDom: !!bar,
            barHiddenComputed: bar ? bar.offsetParent === null : null,
            fieldCount: fields.length,
            fieldIds: fields.map((f) => f.id),
            hasDepartmentField: !!document.getElementById('rdDepartmentFilter'),
            hasPaymentMethodField: !!document.getElementById('rdPaymentMethodFilter'),
            hasSourceField: !!sourceField,
            sourceFieldValue: sourceField ? sourceField.value : null,
            deptColumnFilterBtn: !!document.querySelector('#tb_run_detail thead .tcf-filter-btn[data-tcf-key="department"]'),
            paymentColumnFilterBtn: !!document.querySelector('#tb_run_detail thead .tcf-filter-btn[data-tcf-key="payment_method_code"]'),
            recordsTotal: pageInfo ? pageInfo.recordsTotal : null,
            recordsDisplay: pageInfo ? pageInfo.recordsDisplay : null,
        };
    });
}
async function c1() {
    console.log('\n[c1] #runDetailFilterBar shape -- showDataSourceFilter=true vs run 29685 (false)');

    const ctxTrue = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctxTrue, RUN_752_TOKEN, 'th');
    await openEmployeeTab(ctxTrue.page);
    const shapeTrue = await filterBarShape(ctxTrue.page);
    measured('c1 run 752 (showDataSourceFilter=true)', shapeTrue);
    check('c1/true: #rdDepartmentFilter not in DOM', !shapeTrue.hasDepartmentField, shapeTrue.hasDepartmentField);
    check('c1/true: #rdPaymentMethodFilter not in DOM', !shapeTrue.hasPaymentMethodField, shapeTrue.hasPaymentMethodField);
    check('c1/true: #rdSourceFilter is in DOM', shapeTrue.hasSourceField, shapeTrue.hasSourceField);
    check('c1/true: bar is NOT hidden (computed)', shapeTrue.barExistsInDom && !shapeTrue.barHiddenComputed, shapeTrue);
    check('c1/true: exactly 1 field in the bar', shapeTrue.fieldCount === 1, shapeTrue.fieldCount);
    check('c1/true: department column still has a column-filter button', shapeTrue.deptColumnFilterBtn, shapeTrue.deptColumnFilterBtn);
    check('c1/true: payment_method_code column still has a column-filter button', shapeTrue.paymentColumnFilterBtn, shapeTrue.paymentColumnFilterBtn);
    const repTrue = ctxTrue.report();
    check('c1/true: nothing was written', repTrue.blockedWrites === 0, repTrue.blockedWritePaths.join(','));
    await closeAll();

    const ctxFalse = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctxFalse, RUN_29685_TOKEN, 'th');
    await openEmployeeTab(ctxFalse.page);
    const shapeFalse = await filterBarShape(ctxFalse.page);
    measured('c1 run 29685 (showDataSourceFilter=false)', shapeFalse);
    check('c1/false: #rdDepartmentFilter not in DOM', !shapeFalse.hasDepartmentField, shapeFalse.hasDepartmentField);
    check('c1/false: #rdPaymentMethodFilter not in DOM', !shapeFalse.hasPaymentMethodField, shapeFalse.hasPaymentMethodField);
    check('c1/false: the WHOLE bar is hidden, computed (not just #rdSourceFilter\'s own wrap)', shapeFalse.barExistsInDom && shapeFalse.barHiddenComputed, shapeFalse);
    check('c1/false: #rdSourceFilter was reset to its own default (\'all\') while hidden', shapeFalse.sourceFieldValue === 'all', shapeFalse.sourceFieldValue);
    check('c1/false: department column STILL has a column-filter button (no regression from the hidden bar)', shapeFalse.deptColumnFilterBtn, shapeFalse.deptColumnFilterBtn);
    check('c1/false: payment_method_code column STILL has a column-filter button', shapeFalse.paymentColumnFilterBtn, shapeFalse.paymentColumnFilterBtn);
    if (shapeFalse.recordsTotal === 0) {
        note('c1/false: #tb_run_detail recordsTotal = 0 on run 29685 -- cannot prove recordsDisplay===recordsTotal means "nothing filtered out" on an empty table either way');
    } else {
        check('c1/false: recordsDisplay === recordsTotal (the reset #rdSourceFilter value is not silently narrowing rows)', shapeFalse.recordsDisplay === shapeFalse.recordsTotal, shapeFalse);
    }
    const repFalse = ctxFalse.report();
    check('c1/false: nothing was written (read-only run, nothing clicked)', repFalse.blockedWrites === 0, repFalse.blockedWritePaths.join(','));
    await closeAll();

    // round B2c item: the 3rd real branch of showDataSourceFilter's own condition -- an incentive run
    // that DOES bring base salary in (include_base_salary=1) should show the bar, same as a plain
    // payroll run, via the `Number(currentRun.include_base_salary) === 1` arm (detail.js:2957,
    // post-fix). A fresh read-only SELECT this round (WHERE run_purpose='incentive' AND
    // include_base_salary=1 AND status='active' AND NOT (state='draft' AND auto_recalculate=1))
    // returned ZERO rows -- no such run exists anywhere in dev DB right now (every incentive run here
    // has include_base_salary=0). NOTE instead of fabricating one -- round B1/B2b/B2c's own standing
    // rule against creating fixtures during a measurement-only round.
    note('c1: no real run in dev DB currently matches run_purpose=\'incentive\' AND include_base_salary=1 AND status=\'active\' AND NOT(state=\'draft\' AND auto_recalculate=1) (fresh SELECT, round B2c) -- the "incentive run WITH base salary" branch of showDataSourceFilter cannot be measured against a real run this round');
}

/* ---------------- c2 ---------------- */
// round B2a item 2.3: the exact 6 ids by name (app/views/payroll/detail.php table ids: tb_run_detail
// :616, tb_run_reports :885, tb_run_cash :928, tb_run_bank_account :964, tb_run_remittance :1016,
// tb_run_audit_log :1315), not just a count -- a wrong-but-same-sized set would pass a bare
// `length === 6` check and still be wrong.
const EXPECTED_WRAPPER_IDS = [
    'tb_run_detail_wrapper',
    'tb_run_reports_wrapper',
    'tb_run_cash_wrapper',
    'tb_run_bank_account_wrapper',
    'tb_run_remittance_wrapper',
    'tb_run_audit_log_wrapper',
];
// round B2a item 2.2: ids verified straight from app/views/payroll/detail.php's own tab buttons
// (run-employee-tab:249, run-reports-tab:261, run-cash-tab:279, run-bank-account-tab:291,
// run-remittance-tab:302, run-history-tab:307).
const TAB_IDS = ['run-employee-tab', 'run-reports-tab', 'run-cash-tab', 'run-bank-account-tab', 'run-remittance-tab', 'run-history-tab'];

function gutterShapePerWrapper(page) {
    return page.evaluate((expectedIds) => {
        const result = {};
        for (const id of expectedIds) {
            const wrapper = document.querySelector('#runDetailTabsContent > .tab-pane #' + id);
            if (!wrapper) { result[id] = { found: false }; continue; }
            const rows = Array.from(wrapper.querySelectorAll('.row'));
            const gutters = rows.map((r) => getComputedStyle(r).getPropertyValue('--bs-gutter-x').trim());
            result[id] = { found: true, rowCount: rows.length, gutters, allZero: gutters.length > 0 && gutters.every((g) => g === '0') };
        }
        return result;
    }, EXPECTED_WRAPPER_IDS);
}
// round B2c: run-remittance-tab's own LI is conditionally hidden by updateRunDetailTabVisibility()
// (detail.js:1274-1283) whenever `Number(run.remittance_count || 0)` is 0 (detail.js:1278,1282) --
// NOT a state gate, a genuine "nothing to show" hide, same mechanism as run-cash-tab/
// run-bank-account-tab (cashCount/transferCount > 0). `payroll_remittances` has 0 rows for every run
// in dev DB right now (round A's own COUNT(*), reconfirmed round B2b) -- so this tab is hidden on
// EVERY run currently reachable, including 1014, by design, not a bug. Checked live per-run below
// (never assumed) so a future run/dataset that DOES have remittance rows still gets the real
// assertion instead of silently skipping forever.
function tabLiHidden(page, tabId) {
    return page.evaluate((id) => {
        const btn = document.getElementById(id);
        const li = btn ? btn.closest('li') : null;
        return li ? li.classList.contains('d-none') : null;
    }, tabId);
}
async function c2() {
    console.log('\n[c2] gutter-zero -- every .dt-container .row under #runDetailTabsContent > .tab-pane reads --bs-gutter-x: 0, checked per exact wrapper id');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, RUN_1014_TOKEN, 'th');
    const remittanceTabHidden = await tabLiHidden(ctx.page, 'run-remittance-tab');
    measured('c2 run-remittance-tab li.d-none (by design, remittance_count===0)', remittanceTabHidden);
    // Visit every tab so each DataTable actually renders its own wrapper -- existence/visibility
    // checked first (round B2a item 2.2): a tab that isn't there (a stale id, a page that changed
    // shape) NOTEs and is skipped, rather than page.click() timing out and failing the whole file.
    for (const tabId of TAB_IDS) {
        const handle = await ctx.page.$('#' + tabId);
        const visible = handle ? await handle.isVisible() : false;
        if (!handle || !visible) {
            if (tabId === 'run-remittance-tab' && remittanceTabHidden) {
                note('c2: tab #run-remittance-tab hidden by design (remittance_count===0 for this run) -- tb_run_remittance_wrapper excluded from the expected set for THIS run only, not permanently');
            } else {
                note('c2: tab #' + tabId + ' not found or not visible -- skipped, its own wrapper will read found:false below');
            }
            continue;
        }
        await handle.click();
        await ctx.page.waitForTimeout(400);
    }
    const perWrapper = await gutterShapePerWrapper(ctx.page);
    measured('c2 gutter shape per wrapper', perWrapper);
    for (const id of EXPECTED_WRAPPER_IDS) {
        if (id === 'tb_run_remittance_wrapper' && remittanceTabHidden) {
            continue; // excluded THIS run only -- see the note above and tabLiHidden()'s own comment
        }
        const w = perWrapper[id];
        if (!w.found) {
            check('c2: wrapper #' + id + ' exists under #runDetailTabsContent > .tab-pane', false, w);
            continue;
        }
        if (w.rowCount === 0) {
            check('c2: wrapper #' + id + ' has at least 1 .row', false, w);
            continue;
        }
        check('c2: wrapper #' + id + ' -- all ' + w.rowCount + ' .row element(s) read --bs-gutter-x: 0', w.allZero, w.gutters);
    }
    const rep = ctx.report();
    check('c2: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c3 ---------------- */
async function c3() {
    console.log('\n[c3] "โหมดเดิมไม่แตก" -- a DataTable on a DIFFERENT page + #tb_report_history (sibling of every .tab-pane) keep their own pre-existing gutter, unaffected by the new #runDetailTabsContent-scoped selector');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    // Payroll LIST page -- a real DataTable that was never one of the 6 ids the old rule listed and is
    // nowhere near #runDetailTabsContent (a different page's own DOM entirely). Its own .dt-container
    // .row should read WHATEVER Bootstrap's un-zeroed default gutter is (non-'0'), proving the new
    // selector's scope really is #runDetailTabsContent-only, not leaked app-wide.
    await ctx.page.goto(ctx.url('/payroll-process'), { waitUntil: 'networkidle' });
    await ctx.page.waitForTimeout(900);
    const listShape = await ctx.page.evaluate(() => {
        const row = document.querySelector('.dt-container .row');
        return row ? { found: true, gutter: getComputedStyle(row).getPropertyValue('--bs-gutter-x').trim() } : { found: false, gutter: null };
    });
    measured('c3 payroll list page .dt-container .row gutter', listShape);
    if (!listShape.found) {
        note('c3: no .dt-container .row found on /payroll-process -- cannot compare, check page load/selector');
    } else {
        check('c3: payroll LIST page\'s own gutter is untouched (not zeroed by the new Payroll-Detail-only selector)', listShape.gutter !== '0', listShape.gutter);
    }

    // round B2c: neither run has any report_export_logs row yet (reconfirmed via a fresh read-only
    // SELECT this round: 0 rows for both 1014 and 1015), so a live disabled/enabled check is still
    // needed rather than assuming -- tries 1014 first, then 1015, NOTEs only if NEITHER has an
    // enabled button (never force-clicks a disabled one -- see the selector's own comment below).
    let reportHistoryShape = null;
    let triedRunLabel = null;
    for (const [label, token] of [['run 1014', RUN_1014_TOKEN], ['run 1015', RUN_1015_TOKEN]]) {
        await gotoRun(ctx, token, 'th');
        await ctx.page.click('#run-reports-tab');
        await ctx.page.waitForTimeout(400);
        // Real trigger, confirmed from source: rendered per report row
        // (public/js/payroll/detail.js:527, `class="... btn-report-history"`, `${disabledAttr}`),
        // opens #reportHistoryModal and constructs #tb_report_history as a real DataTable via its own
        // delegated click handler (public/js/payroll/detail.js:1087-1099). `:not([disabled])` -- a
        // disabled instance would hang Playwright's own actionability wait rather than ever firing a
        // click, so it's excluded from the selector itself, not caught after the fact.
        const historyBtn = await ctx.page.$('.btn-report-history:not([disabled])');
        if (!historyBtn) {
            note('c3: no ENABLED .btn-report-history button found on ' + label + '\'s own Reports tab (no report has been downloaded on this run yet)');
            continue;
        }
        triedRunLabel = label;
        await historyBtn.click();
        await ctx.page.waitForFunction(() => window.jQuery && jQuery.fn.dataTable.isDataTable('#tb_report_history'), { timeout: 10000 });
        await ctx.page.waitForTimeout(300);
        reportHistoryShape = await ctx.page.evaluate(() => {
            const row = document.querySelector('#tb_report_history_wrapper .row, #reportHistoryModal .dt-container .row');
            return row ? { found: true, gutter: getComputedStyle(row).getPropertyValue('--bs-gutter-x').trim() } : { found: false, gutter: null };
        });
        break;
    }
    if (!reportHistoryShape) {
        note('c3: #tb_report_history\'s own gutter could not be measured on either run 1014 or run 1015 (no report download history on either) -- needs a run/report with real download history to test this specific case');
    } else {
        measured('c3 #tb_report_history gutter (inside #reportHistoryModal, a sibling of every .tab-pane, ' + triedRunLabel + ')', reportHistoryShape);
        if (!reportHistoryShape.found) {
            note('c3: #tb_report_history has no .row match -- cannot compare');
        } else {
            check('c3: #tb_report_history\'s own gutter is untouched (never covered by the old 6-id list, still not covered by the new .tab-pane-scoped selector)', reportHistoryShape.gutter !== '0', reportHistoryShape.gutter);
        }
    }
    const rep = ctx.report();
    check('c3: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

(async () => {
    await c1();
    await c2();
    await c3();
    console.log('\n===== s_run_detail_c3.js: ' + passCount + ' passed, ' + failCount + ' failed =====');
    process.exit(failCount > 0 ? 1 : 0);
})();
