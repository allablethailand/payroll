/**
 * The ONE line form (#manualLineFormModal) as it behaves for each KIND of line
 * (public/js/payroll/detail.js, 2026-09-18 tiny-L6a).
 *
 * The pencil on a calculated row used to turn its own cell into an amount input. That surface could
 * only ever hold the amount, so a line's note and its destination had no way in from the row they
 * belong to. It now opens the same modal form a hand-added line is edited in -- which means one form
 * has to serve 4 kinds of line whose editable fields genuinely differ, because what stores each one
 * differs:
 *
 *   manual              -> item + amount + note + destination        (payroll_run_manual_lines)
 *   recurring_deduction -> amount + note + destination for this run  (its own per-run override row)
 *   ped                 -> amount + note; destination READ-ONLY      (belongs to the assignment)
 *   base/statutory/...  -> amount + note                             (no destination at all)
 *
 * What this file pins down:
 *  1. which sections each kind renders -- and that a section it may not edit is ABSENT, never a
 *     disabled control (rules.md §0.3/§9);
 *  2. what each kind sends: the item_code/endpoint an override goes to, the note riding along, and
 *     that nothing is sent when nothing actually changed;
 *  3. the 2-request case (a recurring deduction whose amount AND destination both moved): the order
 *     they go in, that they go in SEQUENCE, and what happens when the second one is refused.
 *
 * Same convention as this project's other tests/*.js: no framework, PASS/FAIL lines, nonzero exit,
 * and the functions under test are extracted from the real file (detail.js cannot be require()'d --
 * it is full of top-level jQuery/DOM calls that assume a browser).
 *
 * Scope: behaviour + wiring. How it looks is verified against the live app with Playwright in the
 * same round -- a string test cannot see that.
 */
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const detailJsPath = path.join(root, 'public', 'js', 'payroll', 'detail.js');
const detailSource = fs.readFileSync(detailJsPath, 'utf8');
// 2026-09-19, 4c: the payee-descriptor renderer moved out of payroll/detail.js into its own
// shared file (Employee Detail reuses it). Only the path this suite reads it from changed.
const payeeSource = fs.readFileSync(path.join(root, 'public', 'js', 'payee-descriptor.js'), 'utf8');
const appSource = fs.readFileSync(path.join(root, 'public', 'js', 'app.js'), 'utf8');
const formatSource = fs.readFileSync(path.join(root, 'public', 'js', 'format-helpers.js'), 'utf8');
const LANG_TH = JSON.parse(fs.readFileSync(path.join(root, 'public', 'lang', 'th.json'), 'utf8'));

function sliceBalanced(text, startIdx, what) {
    let depth = 0;
    let i = text.indexOf('{', startIdx);
    for (; i < text.length; i++) {
        if (text[i] === '{') depth++;
        else if (text[i] === '}') { depth--; if (depth === 0) break; }
    }
    if (depth !== 0) throw new Error(`${what} -- no matching closing brace`);
    return text.slice(startIdx, i + 1);
}
function fn(text, name) {
    const idx = text.indexOf(`function ${name}(`);
    if (idx === -1) throw new Error(`${name}() not found -- renamed/removed?`);
    return sliceBalanced(text, idx, `${name}()`);
}
function lineDecl(text, name) {
    const m = text.match(new RegExp(`^const ${name} = .*;$`, 'm'));
    if (!m) throw new Error(`${name} not found -- renamed/removed?`);
    return m[0];
}

/* ---------- a selector-keyed fake DOM, just enough for these functions ---------- */
const stubs = `
// 2026-09-19, 4c: the read-only payee link reads the subject employee's employee_no out of the
// run-detail table (the /employees/ route matches employee_no, not the internal id).
let RUN_DETAIL_ROW = { employee_id: 28, employee_no: 'EM009' };
function runDetailRowByEmployeeId(id) {
    return (RUN_DETAIL_ROW && Number(RUN_DETAIL_ROW.employee_id) === Number(id)) ? RUN_DETAIL_ROW : null;
}
const DOM = {};
const DISABLED = [];
function el(sel) {
    if (!DOM[sel]) DOM[sel] = { classes: new Set(), value: '', checked: false, text: '', attrs: {} };
    return DOM[sel];
}
function $(sel) {
    const e = el(sel);
    const api = {
        hasClass: (c) => e.classes.has(c),
        addClass: (c) => { e.classes.add(c); return api; },
        removeClass: (c) => { e.classes.delete(c); return api; },
        toggleClass: (c, on) => { if (on) e.classes.add(c); else e.classes.delete(c); return api; },
        // Every prop('disabled', ...) any of these functions makes is recorded, because "a section
        // that may not be edited is absent, not disabled" is one of the things under test.
        prop: (k, v) => { if (v === undefined) return e[k]; if (k === 'disabled') DISABLED.push([sel, v]); e[k] = v; return api; },
        val: (v) => { if (v === undefined) return e.value; e.value = (v === null ? '' : v); return api; },
        text: (t) => { if (t === undefined) return e.text; e.text = String(t); return api; },
        attr: (k, v) => { if (v === undefined) return e.attrs[k]; e.attrs[k] = v; return api; },
        removeAttr: (k) => { delete e.attrs[k]; return api; },
        html: () => api,
        empty: () => api,
        trigger: () => api,
        is: (what) => (what === ':checked' ? !!e.checked : false),
        data: () => undefined,
        length: 1,
    };
    return api;
}
$.extend = Object.assign;
// Recorded, never really sent. The recurring-destination write is the only one that goes through
// $.ajax directly (the override write goes through lineOverrideRequestRd, stubbed below).
const AJAX = [];
let ajaxReply = { status: true };
$.ajax = function (opts) { AJAX.push(opts); const r = ajaxReply; if (r.status) opts.success(r); else if (r.error) opts.error(); else opts.success(r); };
const SENT = [];
let overrideReply = { ok: true, message: null };
function lineOverrideRequestRd(lineType, payload, action, done) {
    SENT.push({ lineType: lineType, payload: payload, action: action });
    done(overrideReply.ok, overrideReply.message);
}
const EVENTS = [];
function setButtonLoading($btn, on) { EVENTS.push('loading:' + (on ? 'on' : 'off')); }
function setLineOverrideTableBusyRd(b) { EVENTS.push('tableBusy:' + (b ? 'on' : 'off')); }
function lineOverrideAfterWriteRd() { EVENTS.push('reload'); }
function manualLineFormErrorRd(msg) { EVENTS.push('callout:' + (msg || '')); }
function refreshDirtyGuard() { EVENTS.push('rebaseline'); }
function showSuccess() { EVENTS.push('toast'); }
function lineFormCloseRd() { EVENTS.push('close'); }
function lineOverrideEmployeeIdRd() { return 28; }
function escapeHtml(s) { return s === null || s === undefined ? '' : String(s); }
const PAYROLL_RUN_ID = 752;
const BASE_URL = '';
let currentLang = 'th';
let langData = {};
let manualLineFormCtxRd = null;
let manualLinePayeeEmployeeBlockedRd = false;
let manualLineRowPayeeEmployeeRd = null;
let manualLineRowBankAccountRd = null;
let manualLineRowDestinationRd = null;
let lineOverrideHistoryRd = { byKey: {}, historyAvailable: true, startDate: null };
// 2026-09-19, 4c: the company-account picker IS the "record it?" answer now, so the registry has
// to name it -- payeeDestinationRecords() reads that select instead of a radio pair.
const PAYEE_DEST_REGISTRY = { manualLine: { allowNoRecord: true, companyAccount: '#manualLineBankAccount' } };
function reset() {
    Object.keys(DOM).forEach((k) => delete DOM[k]);
    SENT.length = 0; AJAX.length = 0; EVENTS.length = 0; DISABLED.length = 0;
    overrideReply = { ok: true, message: null };
    ajaxReply = { status: true };
    manualLinePayeeEmployeeBlockedRd = false;
}
`;

const extracted = [
    stubs,
    fn(formatSource, 'parseMoneyInput'),
    fn(formatSource, 'fmtNum'),
    fn(appSource, 'splitOptionCodePrefix'),
    fn(appSource, 'payeeDestinationChoice'),
    fn(appSource, 'payeeDestinationRecords'),
    fn(appSource, 'payeeDestinationType'),
    lineDecl(payeeSource, 'PAYEE_DESCRIPTOR_SEP_RD'),
    lineDecl(payeeSource, 'PAYEE_MASK_SHORT_RD'),
    fn(payeeSource, 'rowOptionLabelRd'),
    fn(payeeSource, 'payeeNameFromLabelRd'),
    fn(payeeSource, 'manualLinePayeeNameRd'),
    fn(payeeSource, 'payeeDescriptorShortMaskRd'),
    fn(payeeSource, 'payeeDescriptorDropAccountNameRd'),
    fn(payeeSource, 'payeeDescriptorTextRd'),
    fn(detailSource, 'lineOverrideHistoryFor'),
    fn(detailSource, 'lineOverrideHistoryValueRd'),
    fn(detailSource, 'lineOverrideComputedTextRd'),
    fn(detailSource, 'lineOverrideEndpointRd'),
    fn(detailSource, 'runSequentialAjaxRd'),
    fn(detailSource, 'lineOverridePayloadRd'),
    fn(detailSource, 'manualLineAmountValueRd'),
    fn(detailSource, 'manualLinePayeeChoiceRd'),
    fn(detailSource, 'lineFormIsOverrideRd'),
    // 2026-09-18, 4b: the tri-state the TH_PIT/TH_SSO rows carry -- real, not stubbed, so what the
    // form's own left slot ('back to inherit') depends on it, so it is real here too.
    lineDecl(detailSource, 'STATUTORY_EXEMPTION_FIELD_RD'),
    'let lineOverrideExemptionRd = null;',
    fn(detailSource, 'statutoryExemptionFieldRd'),
    fn(detailSource, 'statutoryExemptionStateRd'),
    fn(detailSource, 'statutoryExemptionInheritRd'),
    fn(detailSource, 'statutoryExemptionEffectiveRd'),
    fn(detailSource, 'statutoryExemptionChangedRd'),
    fn(detailSource, 'statutoryExemptionTagHtmlRd'),
    fn(detailSource, 'lineFormSectionsRd'),
    fn(detailSource, 'lineFormItemNameRd'),
    fn(detailSource, 'renderLineFormPayeeReadonlyRd'),
    fn(detailSource, 'renderLineFormComputedHintRd'),
    fn(detailSource, 'applyLineFormSectionsRd'),
    fn(detailSource, 'lineOverridePayeeChangedRd'),
    fn(detailSource, 'lineOverrideFormPlanRd'),
    fn(detailSource, 'recurringDestOverridePayloadRd'),
    fn(detailSource, 'submitLineOverrideFormRd'),
    `module.exports = {
        setExemption: (e) => { lineOverrideExemptionRd = e; },
        el, reset,
        sent: () => SENT, ajax: () => AJAX, events: () => EVENTS, disabled: () => DISABLED,
        setOverrideReply: (r) => { overrideReply = r; },
        setAjaxReply: (r) => { ajaxReply = r; },
        setCtx: (c) => { manualLineFormCtxRd = c; },
        setLang: (l, d) => { currentLang = l; langData = d; },
        setPayeeBlocked: (b) => { manualLinePayeeEmployeeBlockedRd = b; },
        lineFormSectionsRd, applyLineFormSectionsRd, lineFormItemNameRd,
        lineOverrideFormPlanRd, lineOverridePayeeChangedRd, recurringDestOverridePayloadRd,
        submitLineOverrideFormRd, lineOverrideEndpointRd, lineOverridePayloadRd,
        renderLineFormComputedHintRd, renderLineFormPayeeReadonlyRd,
    };`,
].join('\n');

const Module = require('module');
const m = new Module(detailJsPath);
m._compile(extracted, detailJsPath);
const api = m.exports;
api.setLang('th', LANG_TH);

let passed = 0;
let failed = 0;
function check(label, cond, extra) {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; console.log(`  FAIL  ${label}${extra !== undefined ? ' -- ' + extra : ''}`); }
}
const $btn = { length: 1 };
const visible = (sel) => !api.el(sel).classes.has('d-none');

/* ---------- the 4 kinds of line, as the .lo-mount payload really shapes them ---------- */
const BASE_LINE = {
    code: '__base_salary__', name_th: 'เงินเดือนพื้นฐาน', name_en: 'Base Salary',
    line_type: 'earning_deduction', item_type: 'base_salary', current_amount: 30000, source: null,
    override_action: null, override_amount: null, override_note: null, payee: null,
};
const STATUTORY_LINE = {
    code: 'TH_SSO', name_th: 'ประกันสังคม', name_en: 'Social Security',
    line_type: 'statutory', item_type: 'statutory', current_amount: 750, source: null,
    override_action: null, override_note: null, payee: null,
};
const PED_LINE = {
    code: 'LOAN', name_th: 'เงินกู้', name_en: 'Loan',
    line_type: 'earning_deduction', item_type: 'deduction', current_amount: 2000, source: 'ped',
    assignment_id: 11, installment_id: 42, override_action: null, override_note: null,
    payee: { payee_type: 'other_person', destination_id: 240, destination_label_th: 'กรมบังคับคดี', destination_label_en: 'Legal Execution Dept.' },
};
const RECURRING_LINE = {
    code: 'COOP', name_th: 'สหกรณ์', name_en: 'Co-op',
    line_type: 'earning_deduction', item_type: 'deduction', current_amount: 1500,
    source: 'recurring_deduction', recurring_id: 7, override_action: null, override_note: null,
    payee: { payee_type: 'company', bank_account_id: 3, bank_account_label_th: 'ธนาคารกรุงศรีอยุธยา • ••••5566 (Trandar)' },
};
const RECURRING_EARNING_LINE = {
    code: 'POSITION', name_th: 'ค่าตำแหน่ง', name_en: 'Position allowance',
    line_type: 'earning_deduction', item_type: 'earning', current_amount: 3000,
    source: 'recurring_earning', recurring_id: 9, override_action: null, override_note: null, payee: null,
};
const ov = (line) => ({ kind: 'override', overrideLine: line, employeeId: 28, mount: '#breakdownLineOverrideWrap' });

console.log('\n=== 1. which sections each kind of line renders ===');
const cases = [
    ['manual (a hand-added line)', { employeeId: 28, itemType: 'deduction' }, { item: true, payee: 'edit', computed: false }],
    ['base salary', ov(BASE_LINE), { item: false, payee: 'none', computed: true }],
    ['statutory', ov(STATUTORY_LINE), { item: false, payee: 'none', computed: true }],
    ['ped assignment (destination read-only)', ov(PED_LINE), { item: false, payee: 'readonly', computed: true }],
    ['recurring deduction (destination editable)', ov(RECURRING_LINE), { item: false, payee: 'edit', computed: true }],
    ['recurring earning (no destination at all)', ov(RECURRING_EARNING_LINE), { item: false, payee: 'none', computed: true }],
];
cases.forEach(function ([label, ctx, want]) {
    const s = api.lineFormSectionsRd(ctx);
    check(`${label}: item=${want.item} payee=${want.payee}`,
        s.item === want.item && s.payee === want.payee && s.computed === want.computed,
        JSON.stringify(s));
});
check('ped with no destination of its own falls back to no payee section',
    api.lineFormSectionsRd(ov(Object.assign({}, PED_LINE, { payee: null }))).payee === 'none');
// 2026-09-19, H-ui: the left slot is GONE, on every kind of line. "Back to what the system
// calculated" is the top row of that line's own history table now -- one way back, not a second one
// behind a pencil, and the only one that can also put back a value that is neither the current nor
// the calculated figure.
check('no line asks for a left-slot "use the calculated value" any more',
    [BASE_LINE, PED_LINE, RECURRING_LINE, Object.assign({}, BASE_LINE, { override_action: 'override_amount' })]
        .every(l => api.lineFormSectionsRd(ov(l)).useComputed === undefined));

console.log('\n=== 2. a section that may not be edited is ABSENT, never disabled ===');
[['base salary', ov(BASE_LINE)], ['ped', ov(PED_LINE)], ['recurring deduction', ov(RECURRING_LINE)],
 ['manual', { employeeId: 28, itemType: 'deduction' }]].forEach(function ([label, ctx]) {
    api.reset();
    const s = api.lineFormSectionsRd(ctx);
    api.applyLineFormSectionsRd(s, ctx);
    const shown = ['#manualLineItemCol', '#manualLinePayeeTypeWrapper', '#manualLinePayeeReadonly'].filter(visible);
    const expected = [];
    if (s.item) expected.push('#manualLineItemCol');
    if (s.payee === 'edit') expected.push('#manualLinePayeeTypeWrapper');
    if (s.payee === 'readonly') expected.push('#manualLinePayeeReadonly');
    check(`${label}: ${expected.length} section(s) rendered, the rest absent`,
        shown.join(',') === expected.join(','), shown.join(',') || '(none)');
    check(`${label}: applying the sections disables nothing`, api.disabled().length === 0,
        JSON.stringify(api.disabled()));
});
api.reset();
api.applyLineFormSectionsRd(api.lineFormSectionsRd(ov(BASE_LINE)), ov(BASE_LINE));
check('the amount field takes the whole row once the item picker is gone',
    api.el('#manualLineAmountCol').classes.has('col-lg-12') && !api.el('#manualLineAmountCol').classes.has('col-lg-4'));
api.reset();
api.applyLineFormSectionsRd(api.lineFormSectionsRd(ov(PED_LINE)), ov(PED_LINE));
check("a ped line's read-only destination links to the employee's own page, by employee_no and tab",
    api.el('#manualLinePayeeReadonlyLink').attrs.href === '/employees/EM009#earningDeduction-tab',
    api.el('#manualLinePayeeReadonlyLink').attrs.href);
check("and states the destination in the shared descriptor's own words",
    api.el('#manualLinePayeeReadonlyText').text.indexOf('กรมบังคับคดี') !== -1,
    api.el('#manualLinePayeeReadonlyText').text);

console.log('\n=== 3. the calculated figure, under the field that replaces it ===');
api.reset();
api.renderLineFormComputedHintRd(ov(BASE_LINE));
check('no override -> the hint IS the live figure', api.el('#manualLineComputedHint').text.indexOf('30,000.00') !== -1,
    api.el('#manualLineComputedHint').text);
api.reset();
api.renderLineFormComputedHintRd({ employeeId: 28, itemType: 'earning' });
check('a hand-added line has no calculated value, so the hint is absent',
    api.el('#manualLineComputedHint').classes.has('d-none'));

console.log('\n=== 4. the header names the line, not the job ===');
check('an override opens on the item name in the language on screen',
    api.lineFormItemNameRd(ov(RECURRING_LINE)) === 'สหกรณ์', api.lineFormItemNameRd(ov(RECURRING_LINE)));
check('a manual line opens on its own item name', api.lineFormItemNameRd({ line: { item_name_th: 'ค่าชุดพนักงาน' } }) === 'ค่าชุดพนักงาน');
check('adding has no line, so it falls back to the add title', api.lineFormItemNameRd({ itemType: 'earning' }) === '');

console.log('\n=== 5. what each kind sends ===');
function prepAmount(value, note) {
    api.reset();
    api.el('#manualLineAmount').value = value;
    api.el('#manualLineComment').value = note === undefined ? '' : note;
    api.el('#manualLinePayeeTypeWrapper').classes.add('d-none');
}
prepAmount('30,000.00');
check('nothing changed -> nothing is sent at all',
    api.lineOverrideFormPlanRd(ov(BASE_LINE)).sendAmount === false);
prepAmount('28,500');
let plan = api.lineOverrideFormPlanRd(ov(BASE_LINE));
check('a changed amount is sent', plan.sendAmount === true && plan.amount === 28500);
prepAmount('30,000.00', 'ปรับตามที่ HR แจ้ง');
check('a note on its own is a change too', api.lineOverrideFormPlanRd(ov(BASE_LINE)).sendAmount === true);
prepAmount('');
check('an empty amount is refused, not sent as 0', api.lineOverrideFormPlanRd(ov(BASE_LINE)).ok === false);
prepAmount('0');
check('0 IS a legitimate override and is accepted', api.lineOverrideFormPlanRd(ov(BASE_LINE)).ok !== false);

check('an earning/deduction override goes to the general endpoint',
    api.lineOverrideEndpointRd('earning_deduction', 'save') === '/api/payroll-run.line-override.save');
check('a statutory override goes to its own endpoint, which wraps the code server-side',
    api.lineOverrideEndpointRd('statutory', 'save') === '/api/payroll-run.statutory-line-override.save');
const p = api.lineOverridePayloadRd('TH_SSO', { action: 'override_amount', amount: 500, note: 'ยกเว้นเดือนนี้' });
check('the payload carries the BARE item code, the amount and the note',
    p.item_code === 'TH_SSO' && p.override_amount === 500 && p.note === 'ยกเว้นเดือนนี้' && p.id === 752 && p.employee_id === 28,
    JSON.stringify(p));
check('a payload with no note at all omits the key rather than sending an empty one',
    api.lineOverridePayloadRd('BASE', { action: 'exclude' }).note === undefined);

console.log('\n=== 6. a recurring deduction: amount and destination are 2 different stores ===');
// The payee picker, set to "an external person" with a saved destination chosen.
function prepPayee(dest, savedId) {
    api.el('#manualLinePayeeDest input[type="radio"]:checked').value = dest;
    if (savedId !== undefined) api.el('#manualLineDestinationSelect').value = savedId;
}
prepAmount('1,500.00');
api.el('#manualLinePayeeTypeWrapper').classes.delete('d-none');
api.el('#manualLineBankAccount').value = '3';
prepPayee('company_retained');
check('same amount, same destination -> neither store is written',
    (function () { const pl = api.lineOverrideFormPlanRd(ov(RECURRING_LINE)); return pl.sendAmount === false && pl.payee === null; })());

prepAmount('1,500.00');
api.el('#manualLinePayeeTypeWrapper').classes.delete('d-none');
prepPayee('external', '240');
plan = api.lineOverrideFormPlanRd(ov(RECURRING_LINE));
check('destination moved, amount did not -> only the destination is written',
    plan.sendAmount === false && plan.payee !== null && plan.payee.payeeType === 'other_person');
const rp = api.recurringDestOverridePayloadRd(7, plan.payee, 'ย้ายปลายทางเฉพาะรอบนี้');
check('the recurring endpoint takes the external account FLAT, keyed by recurring_id',
    rp.recurring_id === 7 && rp.payee_type === 'other_person' && rp.destination_id === '240'
    && rp.destination === undefined && rp.note === 'ย้ายปลายทางเฉพาะรอบนี้', JSON.stringify(rp));

prepAmount('1,200');
api.el('#manualLinePayeeTypeWrapper').classes.delete('d-none');
prepPayee('external', '240');
api.setCtx(ov(RECURRING_LINE));
api.submitLineOverrideFormRd($btn, ov(RECURRING_LINE));
check('both moved -> exactly 2 requests', api.sent().length === 1 && api.ajax().length === 1,
    `${api.sent().length} override + ${api.ajax().length} destination`);
check('the amount goes FIRST (its endpoint has no side effect outside the run)',
    api.events().indexOf('reload') > 0 && api.sent()[0].payload.override_amount === 1200);
check('the destination call is the second, and carries the recurring id',
    api.ajax()[0].url === '/api/payroll-run.recurring-deduction-destination-override.save'
    && JSON.parse(api.ajax()[0].data).recurring_id === 7);
check('both landed -> the table reloads, a toast confirms, the form closes',
    api.events().indexOf('reload') !== -1 && api.events().indexOf('toast') !== -1 && api.events().indexOf('close') !== -1,
    api.events().join(' '));

console.log('\n=== 7. the second request is refused ===');
prepAmount('1,200');
api.el('#manualLinePayeeTypeWrapper').classes.delete('d-none');
prepPayee('external', '240');
api.setAjaxReply({ status: false, message: 'Invalid payee_type.' });
api.setCtx(ov(RECURRING_LINE));
api.submitLineOverrideFormRd($btn, ov(RECURRING_LINE));
check('the amount that DID save is still sent (and not rolled back)', api.sent().length === 1);
check('the table reloads anyway, so the saved amount is visible behind the form',
    api.events().indexOf('reload') !== -1);
check('the refusal is shown in the form, with the server\'s own words',
    api.events().indexOf('callout:Invalid payee_type.') !== -1, api.events().join(' '));
check('the form stays open', api.events().indexOf('close') === -1 && api.events().indexOf('toast') === -1);
check('and its dirty baseline is re-taken, so closing does not ask about what was saved',
    api.events().indexOf('rebaseline') !== -1);

console.log('\n=== 8. the FIRST request is refused ===');
prepAmount('1,200');
api.el('#manualLinePayeeTypeWrapper').classes.delete('d-none');
prepPayee('external', '240');
api.setOverrideReply({ ok: false, message: 'This employee is verified for this run and cannot be edited. Unverify first.' });
api.setCtx(ov(RECURRING_LINE));
api.submitLineOverrideFormRd($btn, ov(RECURRING_LINE));
check('the destination call never fires -- the sequence stops on the first refusal',
    api.sent().length === 1 && api.ajax().length === 0);
check('nothing was created outside the run by a write that could not land',
    api.events().indexOf('close') === -1 && api.events().indexOf('rebaseline') !== -1);

console.log('\n=== 9. a hand-added line never reaches the override endpoint ===');
// Server-side, syncDeductionLinesForEmployee() drops source='manual_line' from this table entirely
// (an override keyed by item_code could not target one line of two sharing a code). Client-side, the
// form's own branch is what keeps a manual line on its own endpoints -- asserted on the real source.
check('the table never lists a manual line for this form to open',
    /\(\$line\['source'\] \?\? null\) === 'manual_line'/.test(
        fs.readFileSync(path.join(root, 'app', 'models', 'PayrollRunModel.php'), 'utf8')));
check("submit routes by the open's own kind, not by anything read off the form",
    /if \(ctx\.kind === 'override'\) \{\s*submitLineOverrideFormRd\(\$btn, ctx\);/.test(detailSource));
check('the inline cell editor is gone from the table for good',
    !/lo-edit-input|lo-amount-edit|lineOverrideOpenEditorRd/.test(detailSource)
    && !/lo-amount-edit/.test(fs.readFileSync(path.join(root, 'public', 'css', 'style.css'), 'utf8')));
check('the pencil is what opens the form',
    /\$\(document\)\.on\('click', '\.lo-mount \.lo-edit-btn', function \(\) \{\s*openLineOverrideFormRd/.test(detailSource));

console.log(`\nPassed: ${passed}, Failed: ${failed}`);
process.exit(failed === 0 ? 0 : 1);
