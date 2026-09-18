/**
 * 4b measurement: what closing Batch 4 leaves behind on the Process Detail page.
 *
 * Run:  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/k4b_close_batch4.js <PHPSESSID> <runToken> <employeeId>
 *
 * 10 cells, each one its own context:
 *   1  the read-only slip's [ยกเลิกการยืนยัน] -- 1 request, and the SAME modal becomes the editable one
 *   2  a run past draft (1015, locked, read-only): [ปิด] alone, no remove button, no ⋮
 *   3  the TH_SSO switch = the tri-state -- 1 request carrying BOTH fields, tag, row still there
 *   4  that row's own restore -> 'inherit' -- the exemption row is DELETED, tag gone, n back
 *   5  [คืนค่าระบบทั้งหมด] with an ordinary override AND a tri-state answer in play
 *   6  an employee with nothing changed: n = 0, and the 2 statutory rows in each slip
 *   7  the DOM the deleted surfaces left: 0 of each, and the row's 3 circles at 1400 and 430
 *   8  430 dark, th then en: every word from langData, and the neutral tone from the theme
 *   9  the history dropdown's "ค่าที่ระบบคำนวณ" row is pressable again -- editable slip only
 *  10  the system groups' own head link: where it is, when it is there, and what it sends
 *
 * Writes: only employee 28's verify flag and tax/SSO answer, on the mksession fixture run, plus
 * one other fixture employee's verify flag -- each put back inside the cell that set it. Run 1015 is
 * read-only. Run 752 / EM009 / CEO are never touched.
 */
'use strict';
const { openContext, closeAll } = require('./harness');

const sessionId = process.argv[2];
const runToken = process.argv[3];
const employeeId = process.argv[4];
if (!sessionId || !runToken || !employeeId) {
    throw new Error('usage: node tests/ui/k4b_close_batch4.js <PHPSESSID> <runToken> <employeeId>');
}

// Fixed fixtures for the 2 cells that do not use the throwaway run (see the header).
const LOCKED_RUN_TOKEN = 'AV_3zH_fu0Ep03a_cT_NpIvAW19tk9dMf0mw0_k69wur'; // run 1015, locked
const LOCKED_EMPLOYEE_ID = 159;
// The n = 0 case was meant to be run 1016 / employee 187. That run is soft-deleted
// (payroll_runs.status='deleted'), so payroll-run.get answers "Record not found" and the page never
// loads -- measured, not assumed. Taken on the fixture run instead, on one of its own employees that
// carries no adjustment at all, which is the same case.
const ZERO_RUN_TOKEN = null;

// --c-text-muted at Light, from tokens.css -- so cell 8 can prove the dark value is a different one.
const LIGHT_MUTED = 'rgb(107, 114, 128)';

const WRAP = '#breakdownLineOverrideWrap';
const MODAL = '#runDetailBreakdownModal';

let passed = 0;
let failed = 0;
function check(label, cond, extra) {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; console.log(`  FAIL  ${label}${extra !== undefined ? ' -- ' + extra : ''}`); }
}

/* ---------- page helpers ---------- */
async function gotoRun(ctx, token, lang) {
    await ctx.page.goto(ctx.url('/payroll-process/' + token), { waitUntil: 'networkidle' });
    await ctx.page.waitForTimeout(700);
    await ctx.page.evaluate((l) => { if (typeof changeLanguage === 'function') changeLanguage(l); }, lang);
    await ctx.page.waitForTimeout(600);
    await ctx.page.waitForFunction(() => typeof currentRun !== 'undefined' && !!currentRun && !!currentRun.state, null, { timeout: 30000 });
}
// The employee table is in its own tab, and a pane that is not shown has no geometry at all
// (measured: every circle came back 0x0). Anything that measures a real box shows it first.
async function showEmployeeTab(page) {
    await page.evaluate(() => bootstrap.Tab.getOrCreateInstance(document.getElementById('run-employee-tab')).show());
    await page.waitForTimeout(800);
}
async function reload(page, lang) {
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(700);
    await page.evaluate((l) => { if (typeof changeLanguage === 'function') changeLanguage(l); }, lang);
    await page.waitForTimeout(600);
}
async function openSlip(page, id) {
    await page.evaluate(() => {
        window.__shows = 0;
        const el = document.getElementById('runDetailBreakdownModal');
        if (!el.__counted) { el.addEventListener('show.bs.modal', () => { window.__shows++; }); el.__counted = true; }
    });
    await page.evaluate((eid) => document.querySelector('.btn-view-breakdown[data-employee-id="' + eid + '"]').click(), id);
    await page.waitForSelector(WRAP + ' tr.lo-row', { timeout: 30000 });
    await page.waitForTimeout(900);
}
async function closeSlip(page) {
    await page.evaluate(() => {
        const inst = bootstrap.Modal.getInstance(document.getElementById('runDetailBreakdownModal'));
        if (inst) inst.hide();
    });
    await page.waitForTimeout(500);
}
async function confirmSwal(page) {
    await page.waitForSelector('.swal2-confirm', { state: 'visible', timeout: 15000 });
    await page.click('.swal2-confirm');
}
async function post(page, endpoint, payload) {
    return page.evaluate(async (args) => {
        const res = await fetch(BASE_URL + '/api/' + args.endpoint, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ id: PAYROLL_RUN_ID }, args.payload)),
        });
        return res.json();
    }, { endpoint, payload });
}
const setVerified = (page, id, verified) => post(page, 'payroll-run.employee-verify.save', { employee_id: Number(id), verified });
const setExemption = (page, id, tax, sso) => post(page, 'payroll-run.save-employee-exemption',
    { employee_id: Number(id), tax_calculate_override: tax, sso_calculate_override: sso });

// The override rows the table currently holds, straight off its own data -- so "one fewer override"
// is counted from the same list the page renders, not from the markup.
async function overrideCodes(page) {
    return page.evaluate(() => (typeof lineOverrideRowsRd !== 'undefined' ? lineOverrideRowsRd : [])
        .filter(l => !!l.override_action)
        .map(l => ({ code: l.code, line_type: l.line_type || 'earning_deduction', action: l.override_action, amount: l.override_amount, note: l.override_note })));
}
async function reseedOverrides(page, list, employee) {
    for (const ov of list) {
        const endpoint = ov.line_type === 'statutory' ? 'payroll-run.statutory-line-override.save' : 'payroll-run.line-override.save';
        const res = await post(page, endpoint, { employee_id: Number(employee), item_code: ov.code, action: ov.action, override_amount: ov.amount, note: ov.note });
        check(`   fixture override ${ov.code} put back`, res && res.status === true, JSON.stringify(res));
    }
}

/* ---------- one pass over the open modal ---------- */
async function measure(page) {
    return page.evaluate((args) => {
        const { wrap, modal } = args;
        const txt = (el) => (el ? el.textContent.replace(/\s+/g, ' ').trim() : null);
        const rowOf = (code) => document.querySelector(wrap + ' tr.lo-row[data-item-code="' + code + '"]');
        const rowInfo = (code) => {
            const r = rowOf(code);
            if (!r) return null;
            const sw = r.querySelector('.lo-include');
            return {
                present: true,
                switchOn: sw ? sw.checked : null,
                switchDisabled: sw ? sw.disabled : null,
                exemptionField: r.getAttribute('data-exemption-field') || '',
                exemptionChanged: r.getAttribute('data-exemption-changed') || '',
                origAction: r.getAttribute('data-orig-action') || '',
                amount: txt(r.querySelector('.lo-amount-view')),
                tags: Array.from(r.querySelectorAll('.lo-amount-cell .payslip-line-tag')).map(txt),
            };
        };
        const footer = document.querySelector(modal + ' .modal-footer');
        const tabs = Array.from(document.querySelectorAll(wrap + ' ul.lo-tabs .nav-link'));
        return {
            footerButtons: footer ? Array.from(footer.querySelectorAll('button')).map(b => ({ id: b.id || '', text: txt(b) })) : [],
            hasUnverify: !!document.querySelector('#btnBreakdownUnverify'),
            hasRestoreAll: !!document.querySelector('#btnRestoreAllComputedLineOverrides'),
            restoreAllDisabled: (() => { const b = document.querySelector('#btnRestoreAllComputedLineOverrides'); return b ? b.disabled : null; })(),
            checkCells: document.querySelectorAll(wrap + ' td.col-check').length,
            actionCells: document.querySelectorAll(wrap + ' td.lo-action-cell').length,
            rowCount: document.querySelectorAll(wrap + ' tr.lo-row').length,
            tabTexts: tabs.map(txt),
            changedTabN: (() => {
                const t = tabs.find(x => x.getAttribute('data-lo-filter') === 'changed');
                if (!t) return null;
                const m = txt(t).match(/\((\d+)\)/);
                return m ? Number(m[1]) : null;
            })(),
            calloutCount: document.querySelectorAll('#breakdownCalcNotes .callout, #breakdownCalcNotes .alert').length,
            calloutText: txt(document.querySelector('#breakdownCalcNotes')),
            pit: rowInfo('TH_PIT'),
            sso: rowInfo('TH_SSO'),
            shows: window.__shows,
        };
    }, { wrap: WRAP, modal: MODAL });
}
// The run's own employee table, measured off the row that belongs to `id`.
async function measureRowActions(page, id) {
    return page.evaluate((eid) => {
        const btn = document.querySelector('.btn-view-breakdown[data-employee-id="' + eid + '"]');
        const cell = btn ? btn.closest('td') : null;
        const circles = cell ? Array.from(cell.querySelectorAll('.btn-circle-action')) : [];
        const box = (el) => { const r = el.getBoundingClientRect(); return { w: Math.round(r.width), h: Math.round(r.height) }; };
        const tokenColor = (name) => {
            const probe = document.createElement('span');
            probe.style.color = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
            document.body.appendChild(probe);
            const c = getComputedStyle(probe).color;
            probe.remove();
            return c;
        };
        const removeBtn = cell ? cell.querySelector('.btn-remove-manual-employee') : null;
        return {
            circles: circles.length,
            sizes: circles.map(box),
            classes: circles.map(c => c.className),
            dropdowns: cell ? cell.querySelectorAll('.dropdown-toggle, .dropdown-menu').length : -1,
            removeIsCircle: !!(removeBtn && removeBtn.classList.contains('btn-circle-action')),
            removeIsListItem: !!(removeBtn && removeBtn.closest('li')),
            removeColor: removeBtn ? getComputedStyle(removeBtn).color : null,
            removePresent: !!removeBtn,
            // Everything 4b deleted, counted across the WHOLE page, not just this cell.
            deadDom: {
                manageLinesModal: document.querySelectorAll('#manageLinesModal').length,
                empAdjustmentsModal: document.querySelectorAll('#empAdjustmentsModal').length,
                eedDestRow: document.querySelectorAll('.eed-dest-row').length,
                manageBtn: document.querySelectorAll('.btn-manage-manual-lines').length,
                // The ROW-ACTIONS menu only: a `.btn-circle-action.dropdown-toggle` carrying the ⋮
                // glyph. The Verify column's own badge caret is also a `.dropdown-toggle` and is
                // still there on purpose (rules.md §7), so counting every toggle would count it too.
                rowDropdowns: document.querySelectorAll('#tb_run_detail .fa-ellipsis-vertical').length,
                verifyBadgeCarets: document.querySelectorAll('#tb_run_detail .badge-dropdown-toggle, #tb_run_detail .dropdown-toggle').length,
                sliders: document.querySelectorAll('#tb_run_detail .fa-sliders').length,
            },
            dangerToken: tokenColor('--c-danger'),
            mutedToken: tokenColor('--c-text-muted'),
        };
    }, id);
}
// Counts only the requests each cell is about.
function trackRequests(page) {
    const seen = { verify: 0, exemption: 0, override: 0, statutory: 0, bodies: [] };
    page.on('request', (req) => {
        const u = req.url();
        if (u.indexOf('payroll-run.employee-verify.save') !== -1) seen.verify++;
        if (u.indexOf('payroll-run.save-employee-exemption') !== -1) {
            seen.exemption++;
            try { seen.bodies.push(JSON.parse(req.postData() || '{}')); } catch (e) { seen.bodies.push(null); }
        }
        if (u.indexOf('payroll-run.line-override.') !== -1) seen.override++;
        if (u.indexOf('payroll-run.statutory-line-override.') !== -1) seen.statutory++;
    });
    return seen;
}
async function langOf(page, keys) {
    return page.evaluate((ks) => {
        const out = {};
        ks.forEach(k => { out[k] = (typeof langData !== 'undefined' && langData[k]) || null; });
        return out;
    }, keys);
}

/* ================================ cells ================================ */

async function cell1() {
    console.log('\n=== 1. 1400 th light -- the read-only slip can unverify, in place ===');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, colorScheme: 'light' });
    const { page, report } = ctx;
    const req = trackRequests(page);
    await gotoRun(ctx, runToken, 'th');
    const seed = await setVerified(page, employeeId, true);
    check('1: seeded verified', seed && seed.status === true, JSON.stringify(seed));
    await reload(page, 'th');
    await openSlip(page, employeeId);

    const before = await measure(page);
    const lang = await langOf(page, ['action_unverify', 'close']);
    console.log(`  footer(view)=${JSON.stringify(before.footerButtons)} checkCells=${before.checkCells} actionCells=${before.actionCells} shows=${before.shows}`);
    check('1: the left slot is [ยกเลิกการยืนยัน], and [ปิด] stays on the right',
        before.hasUnverify && before.footerButtons.length === 2
        && before.footerButtons[0].id === 'btnBreakdownUnverify'
        && before.footerButtons[0].text === lang.action_unverify
        && before.footerButtons[1].text === lang.close,
        JSON.stringify(before.footerButtons));
    check('1: a read-only slip has neither column', before.checkCells === 0 && before.actionCells === 0,
        `${before.checkCells}/${before.actionCells}`);

    const verifyBefore = req.verify;
    await page.click('#btnBreakdownUnverify');
    await confirmSwal(page);
    await page.waitForTimeout(2500);
    const after = await measure(page);
    console.log(`  after: verifyRequests=+${req.verify - verifyBefore} shows=${after.shows} footer=${JSON.stringify(after.footerButtons)}`
        + ` checkCells=${after.checkCells} actionCells=${after.actionCells}`);
    check('1: exactly one verify request', req.verify - verifyBefore === 1, String(req.verify - verifyBefore));
    check('1: the modal was never hidden and shown again', after.shows === 1, String(after.shows));
    check('1: it is the editable slip now -- both columns are back',
        after.checkCells > 0 && after.actionCells > 0, `${after.checkCells}/${after.actionCells}`);
    check('1: ...and the footer is the editable one', after.hasRestoreAll && !after.hasUnverify,
        JSON.stringify(after.footerButtons));

    await closeSlip(page);
    const r = report();
    console.log(`  blocked: prefs=${r.blockedPreferenceSaves} recalc=${r.blockedRecalculates} consoleErrors=${r.consoleErrors.length} pageErrors=${r.pageErrors.length}`);
    check('1: no page error', r.pageErrors.length === 0, JSON.stringify(r.pageErrors));
    await closeAll();
}

async function cell2() {
    console.log('\n=== 2. 1400 th light -- run 1015 (locked), read only ===');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, colorScheme: 'light' });
    const { page, report } = ctx;
    await gotoRun(ctx, LOCKED_RUN_TOKEN, 'th');
    const state = await page.evaluate(() => currentRun.state);
    check('2: the run really is past draft', state === 'locked', state);
    await showEmployeeTab(page);
    const actions = await measureRowActions(page, LOCKED_EMPLOYEE_ID);
    await openSlip(page, LOCKED_EMPLOYEE_ID);
    const m = await measure(page);
    const lang = await langOf(page, ['close']);
    console.log(`  footer=${JSON.stringify(m.footerButtons)} circles=${actions.circles} dropdowns=${actions.dropdowns} remove=${actions.removePresent}`);
    check('2: footer is [ปิด] alone', m.footerButtons.length === 1 && m.footerButtons[0].text === lang.close && !m.hasUnverify,
        JSON.stringify(m.footerButtons));
    check('2: no remove button on a non-draft run', actions.removePresent === false);
    check('2: no ⋮ row-actions menu anywhere in the table', actions.deadDom.rowDropdowns === 0, String(actions.deadDom.rowDropdowns));
    check('2: 2 circles left (slip + comments)', actions.circles === 2, String(actions.circles));
    await closeSlip(page);
    const r = report();
    console.log(`  blocked: prefs=${r.blockedPreferenceSaves} recalc=${r.blockedRecalculates} pageErrors=${r.pageErrors.length}`);
    check('2: no page error', r.pageErrors.length === 0, JSON.stringify(r.pageErrors));
    await closeAll();
}

async function cell3() {
    console.log('\n=== 3. 1400 th light -- the TH_SSO switch writes the tri-state ===');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, colorScheme: 'light' });
    const { page, report } = ctx;
    const req = trackRequests(page);
    await gotoRun(ctx, runToken, 'th');
    await setExemption(page, employeeId, 'inherit', 'inherit');
    await reload(page, 'th');
    await openSlip(page, employeeId);

    const before = await measure(page);
    console.log(`  before: sso=${JSON.stringify(before.sso)}`);
    check('3: the TH_SSO row renders in the editable slip even at 0 / not enrolled',
        !!before.sso && before.sso.present, JSON.stringify(before.sso));
    check('3: it carries the tri-state marker', before.sso && before.sso.exemptionField === 'sso', before.sso && before.sso.exemptionField);
    check('3: at inherit it shows the effective answer and no tag',
        before.sso && before.sso.switchOn === false && before.sso.tags.length === 0, JSON.stringify(before.sso));

    const base = { ex: req.exemption, st: req.statutory, ov: req.override };
    await page.evaluate((wrap) => document.querySelector(wrap + ' tr.lo-row[data-item-code="TH_SSO"] .lo-include').click(), WRAP);
    await page.waitForSelector('.swal2-confirm', { state: 'visible', timeout: 15000 });
    const confirmText = await page.evaluate(() => ({
        title: (document.querySelector('.swal2-title') || {}).textContent,
        body: (document.querySelector('#swal2-html-container, .swal2-html-container') || {}).textContent,
    }));
    const lang = await langOf(page, ['statutory_toggle_confirm_title', 'statutory_toggle_confirm_message', 'calc_override_yes', 'calc_override_no', 'line_override_computed_inline']);
    await page.click('.swal2-confirm');
    // The switch has to be inert while the write is in flight.
    await page.waitForTimeout(150);
    const busy = await page.evaluate((wrap) => {
        const sw = document.querySelector(wrap + ' tr.lo-row[data-item-code="TH_SSO"] .lo-include');
        return { disabled: sw ? sw.disabled : null, wrapBusy: document.querySelector(wrap).classList.contains('lo-table-busy') };
    }, WRAP);
    await page.waitForTimeout(3500);
    const after = await measure(page);
    console.log(`  requests: exemption=+${req.exemption - base.ex} statutory=+${req.statutory - base.st} override=+${req.override - base.ov}`);
    console.log(`  payload=${JSON.stringify(req.bodies[req.bodies.length - 1])}`);
    console.log(`  after: sso=${JSON.stringify(after.sso)} busy=${JSON.stringify(busy)}`);

    check('3: exactly one exemption request, and nothing to the statutory endpoint',
        req.exemption - base.ex === 1 && req.statutory - base.st === 0,
        `${req.exemption - base.ex}/${req.statutory - base.st}`);
    const body = req.bodies[req.bodies.length - 1] || {};
    check('3: it carries BOTH fields', body.tax_calculate_override === 'inherit' && body.sso_calculate_override === 'yes',
        JSON.stringify(body));
    check('3: the switch is inert while it is in flight', busy.disabled === true || busy.wrapBusy === true, JSON.stringify(busy));
    check('3: the row is still there, switched on', after.sso && after.sso.present && after.sso.switchOn === true,
        JSON.stringify(after.sso));
    const expectTag = (lang.line_override_computed_inline || 'System: {amount}').replace('{amount}', lang.calc_override_no);
    check('3: the tag names what inherit would give, as a word', after.sso && after.sso.tags.indexOf(expectTag) !== -1,
        JSON.stringify([after.sso && after.sso.tags, expectTag]));
    check('3: the confirm is the new pair, not the exclude wording',
        (confirmText.title || '').trim() === (lang.statutory_toggle_confirm_title || '').trim(),
        JSON.stringify(confirmText));

    // n is the read-only slip's own count -- measured there, on the same employee.
    await closeSlip(page);
    const nBefore = 2; // the fixture's own 2: one hand-added line, one overridden line
    await setVerified(page, employeeId, true);
    await reload(page, 'th');
    await openSlip(page, employeeId);
    const view = await measure(page);
    console.log(`  read-only slip: tabs=${JSON.stringify(view.tabTexts)} n=${view.changedTabN}`);
    check('3: n counts the tri-state answer too', view.changedTabN === nBefore + 1, `${view.changedTabN} (expected ${nBefore + 1})`);
    await closeSlip(page);
    await setVerified(page, employeeId, false);
    const back = await setExemption(page, employeeId, 'inherit', 'inherit');
    check('3: exemption put back', back && back.status === true, JSON.stringify(back));
    const r = report();
    console.log(`  blocked: prefs=${r.blockedPreferenceSaves} recalc=${r.blockedRecalculates} pageErrors=${r.pageErrors.length}`);
    await closeAll();
}

async function cell4() {
    console.log('\n=== 4. 1400 th light -- the row\'s own restore goes back to inherit ===');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, colorScheme: 'light' });
    const { page, report } = ctx;
    const req = trackRequests(page);
    await gotoRun(ctx, runToken, 'th');
    const seed = await setExemption(page, employeeId, 'inherit', 'no');
    check('4: seeded sso=no', seed && seed.status === true, JSON.stringify(seed));
    await reload(page, 'th');
    await openSlip(page, employeeId);
    const before = await measure(page);
    check('4: the row shows the answer', before.sso && before.sso.exemptionChanged === '1' && before.sso.tags.length === 1,
        JSON.stringify(before.sso));

    const base = { ex: req.exemption, ov: req.override };
    // The form's own left slot is the restore path -- reached from the row's pencil.
    await page.evaluate((wrap) => document.querySelector(wrap + ' tr.lo-row[data-item-code="TH_SSO"] .lo-edit-btn').click(), WRAP);
    await page.waitForSelector('#btnLineFormUseComputed', { state: 'visible', timeout: 15000 });
    check('4: the form offers "ใช้ค่าที่ระบบคำนวณ" even with no amount override', true);
    await page.click('#btnLineFormUseComputed');
    await confirmSwal(page);
    await page.waitForTimeout(3500);
    const after = await measure(page);
    const body = req.bodies[req.bodies.length - 1] || {};
    console.log(`  requests: exemption=+${req.exemption - base.ex} override=+${req.override - base.ov} payload=${JSON.stringify(body)}`);
    console.log(`  after: sso=${JSON.stringify(after.sso)}`);
    check('4: one exemption request, carrying inherit', req.exemption - base.ex === 1 && body.sso_calculate_override === 'inherit',
        `${req.exemption - base.ex} ${JSON.stringify(body)}`);
    check('4: the tag is gone and the row is back to inherit',
        after.sso && after.sso.exemptionChanged === '' && after.sso.tags.length === 0, JSON.stringify(after.sso));
    const dbRows = await page.evaluate(async () => {
        const res = await fetch(BASE_URL + '/api/payroll-run.sync-lines-for-employee?run_id=' + PAYROLL_RUN_ID
            + '&employee_id=' + (typeof breakdownRowRd !== 'undefined' && breakdownRowRd ? breakdownRowRd.employee_id : 0), { credentials: 'same-origin' });
        const j = await res.json();
        return j.exemption || null;
    });
    console.log(`  server now: ${JSON.stringify(dbRows)}`);
    check('4: the server holds inherit on both halves (the row is deleted)',
        dbRows && dbRows.tax_calculate_override === 'inherit' && dbRows.sso_calculate_override === 'inherit',
        JSON.stringify(dbRows));
    await closeSlip(page);
    const r = report();
    console.log(`  blocked: prefs=${r.blockedPreferenceSaves} recalc=${r.blockedRecalculates} pageErrors=${r.pageErrors.length}`);
    await closeAll();
}

async function cell5() {
    console.log('\n=== 5. 1400 th light -- [คืนค่าระบบทั้งหมด] with both kinds in play ===');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, colorScheme: 'light' });
    const { page, report } = ctx;
    const req = trackRequests(page);
    await gotoRun(ctx, runToken, 'th');
    await setExemption(page, employeeId, 'no', 'yes');
    await reload(page, 'th');
    await openSlip(page, employeeId);
    // What the fixture's own override is, so the cell can put it back afterwards.
    const overrides = await page.evaluate(() => (typeof lineOverrideRowsRd !== 'undefined' ? lineOverrideRowsRd : [])
        .filter(l => !!l.override_action)
        .map(l => ({ code: l.code, line_type: l.line_type || 'earning_deduction', action: l.override_action, amount: l.override_amount, note: l.override_note })));
    const before = await measure(page);
    console.log(`  overrides in play=${JSON.stringify(overrides)} restoreAllDisabled=${before.restoreAllDisabled}`);
    check('5: the button is enabled when there is something to restore', before.restoreAllDisabled === false, String(before.restoreAllDisabled));

    const base = { ex: req.exemption, ov: req.override, st: req.statutory };
    await page.click('#btnRestoreAllComputedLineOverrides');
    await confirmSwal(page);
    await page.waitForTimeout(1000 + 2500 * (overrides.length + 1));
    const after = await measure(page);
    const body = req.bodies[req.bodies.length - 1] || {};
    console.log(`  requests: exemption=+${req.exemption - base.ex} override=+${req.override - base.ov} statutory=+${req.statutory - base.st}`);
    console.log(`  last payload=${JSON.stringify(body)} restoreAllDisabled=${after.restoreAllDisabled}`);
    check('5: exactly one extra exemption request', req.exemption - base.ex === 1, String(req.exemption - base.ex));
    check('5: ...carrying inherit on BOTH halves',
        body.tax_calculate_override === 'inherit' && body.sso_calculate_override === 'inherit', JSON.stringify(body));
    check('5: one remove per ordinary override row',
        (req.override - base.ov) + (req.statutory - base.st) === overrides.length,
        `${(req.override - base.ov) + (req.statutory - base.st)} vs ${overrides.length}`);
    check('5: the button switches itself off once there is nothing left',
        after.restoreAllDisabled === true, String(after.restoreAllDisabled));

    // Put the fixture's own override back -- this run outlives the cell.
    for (const ov of overrides) {
        const endpoint = ov.line_type === 'statutory' ? 'payroll-run.statutory-line-override.save' : 'payroll-run.line-override.save';
        const res = await post(page, endpoint, { employee_id: Number(employeeId), item_code: ov.code, action: ov.action, override_amount: ov.amount, note: ov.note });
        check(`5: fixture override ${ov.code} restored`, res && res.status === true, JSON.stringify(res));
    }
    await closeSlip(page);
    const r = report();
    console.log(`  blocked: prefs=${r.blockedPreferenceSaves} recalc=${r.blockedRecalculates} pageErrors=${r.pageErrors.length}`);
    await closeAll();
}

async function cell6() {
    console.log('\n=== 6. 1400 th light -- an employee with nothing changed: n = 0 ===');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, colorScheme: 'light' });
    const { page, report } = ctx;
    await gotoRun(ctx, runToken, 'th');
    // One of this run's own employees with no adjustment of any kind -- the row's own count badge is
    // what says so, read from the table's data rather than guessed.
    const zeroId = await page.evaluate((mine) => {
        const rows = tb_run_detail.rows().data().toArray();
        // 159 (EM009) and 499 (CEO) are off limits for this round, whatever run they appear on.
        const OFF_LIMITS = ['159', '499'];
        const hit = rows.find(r => String(r.employee_id) !== String(mine)
            && OFF_LIMITS.indexOf(String(r.employee_id)) === -1
            && Number(r.adjustment_count || 0) === 0);
        return hit ? String(hit.employee_id) : null;
    }, employeeId);
    check('6: the run has an employee with nothing changed', !!zeroId, String(zeroId));
    if (!zeroId) { await closeAll(); return; }
    await setVerified(page, zeroId, false);
    await reload(page, 'th');
    await openSlip(page, zeroId);
    const edit = await measure(page);
    console.log(`  employee ${zeroId} edit: rows=${edit.rowCount} pit=${JSON.stringify(edit.pit)} sso=${JSON.stringify(edit.sso)}`);
    check('6: both statutory rows render in the editable slip', !!(edit.pit && edit.sso), JSON.stringify([!!edit.pit, !!edit.sso]));
    check('6: each carries its own tri-state field',
        edit.pit && edit.pit.exemptionField === 'tax' && edit.sso && edit.sso.exemptionField === 'sso',
        JSON.stringify([edit.pit && edit.pit.exemptionField, edit.sso && edit.sso.exemptionField]));
    const isZero = (r) => !!r && /^0(\.00)?$/.test((r.amount || '').replace(/,/g, '')) && r.exemptionChanged === '' && r.origAction === '';
    const zeroCodes = [['TH_PIT', edit.pit], ['TH_SSO', edit.sso]].filter(([, r]) => isZero(r)).map(([c]) => c);
    console.log(`  rows at 0 with nothing chosen: ${JSON.stringify(zeroCodes)}`);
    check('6: at least one of the 2 really is 0 here (the case this cell is about)', zeroCodes.length > 0, JSON.stringify(zeroCodes));
    await closeSlip(page);

    await setVerified(page, zeroId, true);
    await reload(page, 'th');
    await openSlip(page, zeroId);
    const view = await measure(page);
    console.log(`  view: rows=${view.rowCount} tabs=${JSON.stringify(view.tabTexts)} pit=${!!view.pit} sso=${!!view.sso}`);
    check('6: nothing changed -> no tab row at all', view.tabTexts.length === 0, JSON.stringify(view.tabTexts));
    check('6: a 0 row with nothing chosen is absent from the read-only slip',
        zeroCodes.every(c => (c === 'TH_PIT' ? !view.pit : !view.sso)), JSON.stringify(zeroCodes));
    check('6: a row with a real figure is still there',
        ['TH_PIT', 'TH_SSO'].filter(c => zeroCodes.indexOf(c) === -1)
            .every(c => (c === 'TH_PIT' ? !!view.pit : !!view.sso)),
        JSON.stringify([!!view.pit, !!view.sso]));
    await closeSlip(page);
    const restore = await setVerified(page, zeroId, false);
    check('6: verify flag restored', restore && restore.status === true, JSON.stringify(restore));
    const r = report();
    console.log(`  blocked: prefs=${r.blockedPreferenceSaves} recalc=${r.blockedRecalculates} pageErrors=${r.pageErrors.length}`);
    await closeAll();
}

async function cell7() {
    console.log('\n=== 7. 1400 th light -- what is gone, and the 3 circles ===');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, colorScheme: 'light' });
    const { page, report } = ctx;
    await gotoRun(ctx, runToken, 'th');
    await showEmployeeTab(page);
    const a = await measureRowActions(page, employeeId);
    console.log(`  deadDom=${JSON.stringify(a.deadDom)}`);
    console.log(`  circles=${a.circles} sizes=${JSON.stringify(a.sizes)} dropdowns=${a.dropdowns} removeIsCircle=${a.removeIsCircle}`);
    check('7: #manageLinesModal is gone', a.deadDom.manageLinesModal === 0, String(a.deadDom.manageLinesModal));
    check('7: #empAdjustmentsModal is gone', a.deadDom.empAdjustmentsModal === 0, String(a.deadDom.empAdjustmentsModal));
    check('7: .eed-dest-row is gone', a.deadDom.eedDestRow === 0, String(a.deadDom.eedDestRow));
    check('7: .btn-manage-manual-lines is gone', a.deadDom.manageBtn === 0, String(a.deadDom.manageBtn));
    check('7: no ⋮ row-actions menu in the employee table', a.deadDom.rowDropdowns === 0, String(a.deadDom.rowDropdowns));
    check('7: the fa-sliders settings circle is gone from the table', a.deadDom.sliders === 0, String(a.deadDom.sliders));
    check('7: 3 circles on a draft row', a.circles === 3, String(a.circles));
    check('7: ...and remove is one of them, not a menu item', a.removeIsCircle && !a.removeIsListItem, JSON.stringify([a.removeIsCircle, a.removeIsListItem]));
    check('7: every circle is 32x32 at 1400', a.sizes.every(s => s.w === 32 && s.h === 32), JSON.stringify(a.sizes));
    await closeAll();

    const ctx2 = await openContext({ sessionId, width: 430, height: 900, colorScheme: 'light' });
    await gotoRun(ctx2, runToken, 'th');
    await showEmployeeTab(ctx2.page);
    const b = await measureRowActions(ctx2.page, employeeId);
    console.log(`  430: circles=${b.circles} sizes=${JSON.stringify(b.sizes)}`);
    check('7: still 3 circles at 430', b.circles === 3, String(b.circles));
    check('7: ...and none of them is squeezed', b.sizes.every(s => s.w === 32 && s.h === 32), JSON.stringify(b.sizes));
    const r2 = ctx2.report();
    console.log(`  blocked: prefs=${r2.blockedPreferenceSaves} recalc=${r2.blockedRecalculates} pageErrors=${r2.pageErrors.length}`);
    await closeAll();
}

async function cell8() {
    console.log('\n=== 8. 430 dark -- th then en: the words and the tone ===');
    const ctx = await openContext({ sessionId, width: 430, height: 900, colorScheme: 'dark' });
    const { page, report } = ctx;
    await gotoRun(ctx, runToken, 'th');
    // The page stamps `data-bs-theme` from the viewer's SAVED preference (header.php), so an
    // OS-level dark context on an account saved as 'light' still renders the light tokens --
    // measured, not assumed. applyTheme() is exactly what the app's own toggle calls, and the
    // write-back that would follow it is what the harness blocks.
    const stampBefore = await page.evaluate(() => document.documentElement.getAttribute('data-bs-theme'));
    await page.evaluate(() => applyTheme('dark'));
    await page.waitForTimeout(400);
    const stampAfter = await page.evaluate(() => document.documentElement.getAttribute('data-bs-theme'));
    console.log(`  theme stamp: ${stampBefore} -> ${stampAfter}`);
    check('8: the dark theme is really the one in force', stampAfter === 'dark', String(stampAfter));
    await showEmployeeTab(page);
    const th = await measureRowActions(page, employeeId);
    const thLang = await langOf(page, ['action_remove', 'action_unverify', 'statutory_toggle_confirm_title', 'calc_override_yes', 'calc_override_no']);
    const thTitle = await page.evaluate(() => {
        const b = document.querySelector('.btn-remove-manual-employee');
        return b ? b.getAttribute('title') : null;
    });
    console.log(`  th: removeTitle="${thTitle}" removeColor=${th.removeColor} muted=${th.mutedToken} danger=${th.dangerToken}`);
    check('8/th: the remove circle is labelled from langData', thTitle === thLang.action_remove, `${thTitle} vs ${thLang.action_remove}`);
    // rules.md §5: red is a STATUS colour, never an action's. The remove circle carries no tone class
    // at all -- markup and rendering say the same thing, rather than a `.text-danger` the shared
    // `.btn-circle-action` rule would override anyway. What it does have to follow is the THEME.
    check('8/th: no tone class on the remove circle at all',
        th.classes.every(c => c.indexOf('text-danger') === -1 && c.indexOf('text-success') === -1),
        JSON.stringify(th.classes));
    check('8/th: ...and it renders the muted token of the DARK theme',
        th.removeColor === th.mutedToken && th.removeColor !== th.dangerToken,
        `${th.removeColor} muted=${th.mutedToken} danger=${th.dangerToken}`);
    check('8/th: ...and that token is the dark one, not the light value',
        th.mutedToken !== LIGHT_MUTED, `${th.mutedToken} (light is ${LIGHT_MUTED})`);
    check('8/th: every new key has a Thai value',
        !!thLang.statutory_toggle_confirm_title && !!thLang.calc_override_yes && !!thLang.calc_override_no,
        JSON.stringify(thLang));

    // Open the editable slip once in dark, so the tag/switch really render under this theme.
    await openSlip(page, employeeId);
    const slipTh = await measure(page);
    console.log(`  th slip: sso=${JSON.stringify(slipTh.sso)} footer=${JSON.stringify(slipTh.footerButtons)}`);
    check('8/th: the TH_SSO row is there under the dark theme too', !!(slipTh.sso && slipTh.sso.present));
    await closeSlip(page);

    await page.evaluate(() => { if (typeof changeLanguage === 'function') changeLanguage('en'); });
    await page.waitForTimeout(900);
    const enLang = await langOf(page, ['action_remove', 'action_unverify', 'statutory_toggle_confirm_title', 'calc_override_yes', 'calc_override_no']);
    const enTitle = await page.evaluate(() => {
        const b = document.querySelector('.btn-remove-manual-employee');
        return b ? b.getAttribute('title') : null;
    });
    console.log(`  en: removeTitle="${enTitle}" keys=${JSON.stringify(enLang)}`);
    check('8/en: every new key has an English value',
        !!enLang.statutory_toggle_confirm_title && !!enLang.calc_override_yes && !!enLang.calc_override_no,
        JSON.stringify(enLang));
    check('8/en: the 2 languages really differ (the sweep ran)',
        enLang.statutory_toggle_confirm_title !== thLang.statutory_toggle_confirm_title);
    const r = report();
    console.log(`  blocked: prefs=${r.blockedPreferenceSaves} recalc=${r.blockedRecalculates} pageErrors=${r.pageErrors.length}`);
    check('8: preference write-back was blocked, not saved', r.blockedPreferenceSaves > 0, String(r.blockedPreferenceSaves));
    await page.evaluate((t) => applyTheme(t || 'system'), stampBefore);
    await closeAll();
}

async function cell9() {
    console.log('\n=== 9. 1400 th light -- the history dropdown offers the calculated value again ===');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, colorScheme: 'light' });
    const { page, report } = ctx;
    const req = trackRequests(page);
    await gotoRun(ctx, runToken, 'th');
    await openSlip(page, employeeId);
    const seeded = await overrideCodes(page);
    check('9: the fixture really has an overridden row to measure', seeded.length > 0, JSON.stringify(seeded));
    if (!seeded.length) { await closeAll(); return; }
    const code = seeded[0].code;

    const menu = await page.evaluate((args) => {
        const row = document.querySelector(args.wrap + ' tr.lo-row[data-item-code="' + args.code + '"]');
        const toggle = row ? row.querySelector('.lo-history-toggle') : null;
        if (toggle) toggle.click();
        const head = row ? row.querySelector('.lo-history-computed') : null;
        return {
            hasBadge: !!toggle,
            headExists: !!head,
            headIsButton: !!(head && head.tagName === 'BUTTON'),
            headIsStatic: !!(head && head.classList.contains('lo-history-item-static')),
            headText: head ? head.textContent.replace(/\s+/g, ' ').trim() : null,
            pressableItems: row ? Array.from(row.querySelectorAll('button.lo-history-item')).filter(b => !b.disabled).length : -1,
            // The calculated-value row specifically -- the count above also holds every past value,
            // and how many of those exist depends on what earlier cells did to this run.
            pressableComputed: row ? Array.from(row.querySelectorAll('button.lo-history-computed')).filter(b => !b.disabled).length : -1,
        };
    }, { wrap: WRAP, code });
    console.log(`  menu on ${code}: ${JSON.stringify(menu)}`);
    check('9: the row carries a history badge', menu.hasBadge);
    check('9: the calculated-value row is a pressable item, not a static line',
        menu.headExists && menu.headIsButton && !menu.headIsStatic, JSON.stringify(menu));
    check('9: ...exactly one of them, and it is the calculated-value row',
        menu.pressableComputed === 1, String(menu.pressableComputed));

    const base = { ov: req.override, st: req.statutory };
    await page.evaluate((args) => document.querySelector(args.wrap + ' tr.lo-row[data-item-code="' + args.code + '"] .lo-history-computed').click(),
        { wrap: WRAP, code });
    await confirmSwal(page);
    await page.waitForTimeout(3500);
    const after = await overrideCodes(page);
    console.log(`  overrides ${seeded.length} -> ${after.length} | requests: override=+${req.override - base.ov} statutory=+${req.statutory - base.st}`);
    check('9: pressing it drops that row\'s override', after.length === seeded.length - 1,
        `${seeded.length} -> ${after.length}`);
    check('9: exactly one write went out', (req.override - base.ov) + (req.statutory - base.st) === 1,
        String((req.override - base.ov) + (req.statutory - base.st)));
    await closeSlip(page);
    await reseedOverrides(page, seeded, employeeId);

    // ...and the read-only slip still offers nothing to press.
    await setVerified(page, employeeId, true);
    await reload(page, 'th');
    await openSlip(page, employeeId);
    const view = await page.evaluate((args) => {
        const row = document.querySelector(args.wrap + ' tr.lo-row[data-item-code="' + args.code + '"]');
        const toggle = row ? row.querySelector('.lo-history-toggle') : null;
        if (toggle) toggle.click();
        return {
            headButtons: row ? row.querySelectorAll('button.lo-history-computed').length : -1,
            headStatic: row ? row.querySelectorAll('.lo-history-computed.lo-history-item-static').length : -1,
        };
    }, { wrap: WRAP, code });
    console.log(`  read-only slip: ${JSON.stringify(view)}`);
    check('9: the read-only slip renders it as a fact, never a control',
        view.headButtons === 0 && view.headStatic === 1, JSON.stringify(view));
    await closeSlip(page);
    const restore = await setVerified(page, employeeId, false);
    check('9: verify flag restored', restore && restore.status === true, JSON.stringify(restore));
    const r = report();
    console.log(`  blocked: prefs=${r.blockedPreferenceSaves} recalc=${r.blockedRecalculates} pageErrors=${r.pageErrors.length}`);
    await closeAll();
}

async function cell10() {
    console.log('\n=== 10. 1400 th light -- the system groups\' own head link ===');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, colorScheme: 'light' });
    const { page, report } = ctx;
    const req = trackRequests(page);
    await gotoRun(ctx, runToken, 'th');
    await openSlip(page, employeeId);
    const seeded = await overrideCodes(page);
    check('10: the fixture really has an overridden row to measure', seeded.length > 0, JSON.stringify(seeded));
    if (!seeded.length) { await closeAll(); return; }

    const heads = await page.evaluate((wrap) => {
        const rowsOf = (type) => Array.from(document.querySelectorAll(wrap + ' tr.lo-row[data-group-type="' + type + '"]'));
        return Array.from(document.querySelectorAll(wrap + ' tr.lo-group')).map((g) => {
            const restore = g.querySelector('.lo-group-restore-btn');
            const add = g.querySelector('.lo-add-line-btn');
            const type = restore ? restore.getAttribute('data-group-type') : null;
            return {
                label: (g.querySelector('.lo-span-sticky') || {}).textContent.trim(),
                restore: g.querySelectorAll('.lo-group-restore-btn').length,
                add: g.querySelectorAll('.lo-add-line-btn').length,
                restoreText: restore ? restore.textContent.trim() : null,
                restoreRight: restore ? restore.getBoundingClientRect().right : null,
                addRight: add ? add.getBoundingClientRect().right : null,
                type,
                overrideRows: type ? rowsOf(type).filter(r => (r.getAttribute('data-orig-action') || '') !== ''
                    || !!r.getAttribute('data-exemption-changed')).length : null,
            };
        });
    }, WRAP);
    heads.forEach(h => console.log(`  head "${h.label}": restore=${h.restore} add=${h.add} rows=${h.overrideRows} right=${h.restoreRight !== null ? h.restoreRight.toFixed(2) : '-'}`));
    const withRestore = heads.filter(h => h.restore === 1);
    const withoutRestore = heads.filter(h => h.restore === 0 && h.add === 0);
    const addRight = heads.map(h => h.addRight).filter(v => v !== null)[0];
    check('10: exactly the groups that hold something to restore carry the link',
        withRestore.length >= 1 && withRestore.every(h => h.overrideRows > 0), JSON.stringify(withRestore.map(h => [h.label, h.overrideRows])));
    check('10: a group with nothing to restore renders no link at all (absent, not disabled)',
        withoutRestore.every(h => h.restore === 0), JSON.stringify(withoutRestore.map(h => h.label)));
    check('10: never both links on one head', heads.every(h => !(h.restore && h.add)), JSON.stringify(heads.map(h => [h.restore, h.add])));
    check('10: its right edge is the same x as the manual groups\' own link',
        addRight !== undefined && withRestore.every(h => Math.abs(h.restoreRight - addRight) <= 0.5),
        JSON.stringify([addRight, withRestore.map(h => h.restoreRight)]));

    const target = withRestore[0];
    const base = { ov: req.override, st: req.statutory, ex: req.exemption };
    await page.evaluate((args) => document.querySelector(args.wrap + ' .lo-group-restore-btn[data-group-type="' + args.type + '"]').click(),
        { wrap: WRAP, type: target.type });
    await page.waitForSelector('.swal2-confirm', { state: 'visible', timeout: 15000 });
    const confirmText = await page.evaluate(() => (document.querySelector('#swal2-html-container, .swal2-html-container') || {}).textContent);
    await page.click('.swal2-confirm');
    await page.waitForTimeout(1000 + 2500 * (target.overrideRows + 1));
    const sent = (req.override - base.ov) + (req.statutory - base.st) + (req.exemption - base.ex);
    const after = await page.evaluate((args) => document.querySelectorAll(args.wrap + ' .lo-group-restore-btn[data-group-type="' + args.type + '"]').length,
        { wrap: WRAP, type: target.type });
    console.log(`  clicked "${target.label}": rows=${target.overrideRows} requests=${sent} linkAfter=${after}`);
    console.log(`  confirm names the group: ${confirmText && confirmText.indexOf(target.label) !== -1}`);
    check('10: one request per row it said it would restore', sent === target.overrideRows, `${sent} vs ${target.overrideRows}`);
    check('10: the confirm names the group', !!confirmText && confirmText.indexOf(target.label) !== -1, String(confirmText));
    check('10: the link is gone once there is nothing left in that group', after === 0, String(after));
    await closeSlip(page);
    await reseedOverrides(page, seeded, employeeId);
    const r = report();
    console.log(`  blocked: prefs=${r.blockedPreferenceSaves} recalc=${r.blockedRecalculates} pageErrors=${r.pageErrors.length}`);
    await closeAll();
}

// A re-run after a fix does not have to pay for the 6 cells it did not touch:
//   ONLY_CELLS=7,8 npx -p playwright node tests/ui/k4b_close_batch4.js ...
// Absent = every cell, which is what a full round is.
const CELLS = [cell1, cell2, cell3, cell4, cell5, cell6, cell7, cell8, cell9, cell10];
const only = String(process.env.ONLY_CELLS || '').split(',').map(v => v.trim()).filter(Boolean).map(Number);

(async () => {
    try {
        for (let i = 0; i < CELLS.length; i++) {
            if (only.length && only.indexOf(i + 1) === -1) continue;
            await CELLS[i]();
        }
    } catch (e) {
        failed++;
        console.log('  FAIL  round threw -- ' + (e && e.stack ? e.stack : e));
        await closeAll();
    }
    console.log('\n' + '-'.repeat(50));
    console.log(`Passed: ${passed}, Failed: ${failed}`);
    process.exit(failed ? 1 : 0);
})();
