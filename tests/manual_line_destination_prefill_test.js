/**
 * #manualLineFormModal -- the saved-destination half of the payee picker in EDIT mode
 * (public/js/payroll/detail.js, 2026-09-17 tiny-M).
 *
 * Two real bugs, both found by measuring the live app, both about the same field:
 *  (a) `refreshManualLineSavedDestinationsRd()` is async. On the FIRST open of the form in a page
 *      load its answer landed ~124ms AFTER prefill and, for a company with no saved destinations,
 *      called setManualLineDestModeRd('new') -- which wipes the select -- so the destination the
 *      line actually points at vanished from the form. On every later open the cached answer made
 *      the same call run INSIDE prefill, so prefill won and the form looked different for the same
 *      row. The fix has to make BOTH orders end identically; this file runs both.
 *  (b) `payment-destination.options` only returns is_saved = 1 rows, so an ad-hoc destination had
 *      no option to be represented by -- and prefill's own summary box was never filled at all,
 *      because programmatic selection does not fire select2:select.
 *
 * Same convention as this project's other tests/*.js: no framework, PASS/FAIL lines, nonzero exit,
 * and the functions under test are extracted from the real file (detail.js cannot be require()'d).
 *
 * Scope: behaviour + wiring. How it looks is verified with Playwright in the same round.
 */
const fs = require('fs');
const path = require('path');

const detailJsPath = path.join(__dirname, '..', 'public', 'js', 'payroll', 'detail.js');
const appJsPath = path.join(__dirname, '..', 'public', 'js', 'app.js');
const inputJsPath = path.join(__dirname, '..', 'public', 'js', 'input.js');
const detailSource = fs.readFileSync(detailJsPath, 'utf8');
const appSource = fs.readFileSync(appJsPath, 'utf8');
const inputSource = fs.readFileSync(inputJsPath, 'utf8');

function sliceBalanced(text, startIdx, what) {
    const braceStart = text.indexOf('{', startIdx);
    let depth = 0;
    let i = braceStart;
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
function handler(text, event, selector) {
    const marker = `$(document).on('${event}', '${selector}'`;
    const idx = text.indexOf(marker);
    if (idx === -1) throw new Error(`${event} handler for ${selector} not found`);
    return sliceBalanced(text, idx, `${event}(${selector})`);
}
function stripComments(src) {
    return src.replace(/\/\*[\s\S]*?\*\//g, '').split('\n').filter((l) => !/^\s*\/\//.test(l)).join('\n');
}

/* ---------- a selector-keyed fake DOM, just enough for these functions ---------- */
const stubs = `
const DOM = {};
function el(sel) {
    if (!DOM[sel]) DOM[sel] = { classes: new Set(), value: '', checked: false, html: '', options: [] };
    return DOM[sel];
}
let initCalls = [];
let currentLang = 'th';
function $(sel) {
    const e = el(sel);
    const api = {
        hasClass: (c) => e.classes.has(c),
        addClass: (c) => { e.classes.add(c); return api; },
        removeClass: (c) => { e.classes.delete(c); return api; },
        toggleClass: (c, on) => { if (on) e.classes.add(c); else e.classes.delete(c); return api; },
        prop: (k, v) => { if (v === undefined) return e[k]; e[k] = v; return api; },
        val: (v) => { if (v === undefined) return e.value; e.value = (v === null ? '' : v); return api; },
        html: (h) => { if (h === undefined) return e.html; e.html = h; return api; },
        empty: () => { e.html = ''; return api; },
        trigger: () => api,
        is: (what) => (what === ':checked' ? !!e.checked : false),
        attr: () => api,
        data: () => undefined,
    };
    return api;
}
function escapeHtml(str) { if (str === null || str === undefined) return ''; return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
// The real one is in input.js; here it records what a caller asked for and applies the one effect
// the functions under test depend on -- a pinned option that opens selected lands in the field.
function initSelect2(selector, options) {
    initCalls.push({ selector: selector, options: options });
    const p = options && options.pinnedOption;
    if (p && p.selected) el(selector).value = String(p.id);
    if (options && options.pinnedOption === null) el(selector).pinnedCleared = true;
    return undefined;
}
let manualLineDestHasSavedRd = null;
function setDestHasSaved(v) { manualLineDestHasSavedRd = v; }
let manualLinePayeeEmployeeBlockedRd = false;
let addStateRefreshes = 0;
function refreshManualLineAddStateRd() { addStateRefreshes++; }
const langData = { payee_employee_no_bank_account: 'This employee has no bank account on file yet' };
const BASE_URL = '';
// $.post is only reached by applyDefaultCompanyBankAccount(); the canned reply is set per test.
let cannedBankAccounts = [];
$.post = function (url, data, cb) { cb({ status: true, data: { items: cannedBankAccounts } }); return { fail: () => {} }; };
function resetDom() { Object.keys(DOM).forEach((k) => delete DOM[k]); initCalls = []; }
`;

const extracted = stubs + '\n'
    + fn(appSource, 'payeeDetailHtml') + '\n'
    + fn(appSource, 'payeeDetailFromOption') + '\n'
    + fn(appSource, 'applyDefaultCompanyBankAccount') + '\n'
    + fn(detailSource, 'setManualLineDestModeRd') + '\n'
    + fn(detailSource, 'pinManualLineRowOptionRd') + '\n'
    + fn(detailSource, 'unpinManualLineRowOptionRd') + '\n'
    + fn(detailSource, 'manualLineRowLabelRd') + '\n'
    + fn(detailSource, 'renderManualLineBankAccountDetailRd') + '\n'
    + fn(detailSource, 'renderManualLinePayeeEmployeeDetailRd') + '\n'
    + fn(detailSource, 'applyManualLineRowPayeeEmployeeRd') + '\n'
    + fn(detailSource, 'applyManualLineRowBankAccountRd') + '\n'
    + fn(detailSource, 'manualLineHasPinnedDestinationRd') + '\n'
    + fn(detailSource, 'syncManualLineDestModeToggleRd') + '\n'
    + fn(detailSource, 'applyManualLineDestAvailabilityRd') + '\n'
    + fn(detailSource, 'applyManualLineRowDestinationRd') + '\n'
    + fn(detailSource, 'clearManualLineRowDestinationRd') + '\n'
    + 'let manualLineRowDestinationRd = null;\nlet manualLineRowPayeeEmployeeRd = null;\nlet manualLineRowBankAccountRd = null;\n'
    + `module.exports = {
        el, resetDom, setDestHasSaved,
        getInitCalls: () => initCalls,
        setRowDestination: (d) => { manualLineRowDestinationRd = d; },
        getRowDestination: () => manualLineRowDestinationRd,
        setManualLineDestModeRd, applyManualLineDestAvailabilityRd, applyManualLineRowDestinationRd,
        clearManualLineRowDestinationRd, manualLineHasPinnedDestinationRd, syncManualLineDestModeToggleRd,
        applyManualLineRowPayeeEmployeeRd, applyManualLineRowBankAccountRd, applyDefaultCompanyBankAccount,
        manualLineRowLabelRd, renderManualLineBankAccountDetailRd, renderManualLinePayeeEmployeeDetailRd,
        setRowPayeeEmployee: (d) => { manualLineRowPayeeEmployeeRd = d; },
        setRowBankAccount: (d) => { manualLineRowBankAccountRd = d; },
        getRowPayeeEmployee: () => manualLineRowPayeeEmployeeRd,
        getRowBankAccount: () => manualLineRowBankAccountRd,
        setLang: (l) => { currentLang = l; },
        setCannedBankAccounts: (x) => { cannedBankAccounts = x; },
        isPayeeBlocked: () => manualLinePayeeEmployeeBlockedRd,
    };`;

const Module = require('module');
const m = new Module(detailJsPath);
m._compile(extracted, detailJsPath);
const api = m.exports;

const SELECT = '#manualLineDestinationSelect';
const DETAIL = '#manualLineDestinationDetail';
const TOGGLE = '#manualLineDestModeToggle';
const SAVED = '#manualLineDestSavedFields';
const NEWF = '#manualLineDestinationNewFields';

let passed = 0;
let failed = 0;
function check(label, cond, detail) {
    if (cond) { passed++; console.log(`  PASS  ${label}${detail ? ' -- ' + detail : ''}`); }
    else { failed++; console.log(`  FAIL  ${label}${detail ? ' -- ' + detail : ''}`); }
}
const visible = (sel) => !api.el(sel).classes.has('d-none');

// The row under test: an is_saved = 0 destination, i.e. one the picker's endpoint never returns.
const AD_HOC_ROW = {
    id: 240,
    text: 'กรมบังคับคดี',
    is_saved: 0,
    data: {
        account_name: 'กรมบังคับคดี',
        bank_name_th: 'ธนาคารกรุงไทย',
        bank_name_en: 'Krungthai Bank',
        bank_branch: 'สวนหลวง',
        account_no_masked: 'XXXXXX7890',
    },
};

function openFresh() {
    api.resetDom();
    api.setRowDestination(JSON.parse(JSON.stringify(AD_HOC_ROW)));
}

/* ================================================================ */
console.log('=== (a) prefill wins whichever way round the async answer arrives ===');

// ORDER 1 -- cold cache: the lookup answers AFTER prefill has put the row's destination in.
openFresh();
api.setDestHasSaved(null);
api.applyManualLineRowDestinationRd();
api.applyManualLineDestAvailabilityRd(false); // the late response, company has nothing saved
const cold = {
    value: api.el(SELECT).value, saved: visible(SAVED), neu: visible(NEWF), toggle: visible(TOGGLE),
    detailLen: api.el(DETAIL).html.length,
};
check('cold order: the row destination is still in the field (it used to be wiped)', cold.value === '240', `value="${cold.value}"`);
check('cold order: the saved half is the one on screen', cold.saved === true && cold.neu === false);
check('cold order: the saved/new choice is offered (the row itself is something to choose)', cold.toggle === true);

// ORDER 2 -- warm cache: the same call runs BEFORE prefill gets to the field.
openFresh();
api.setDestHasSaved(false);
api.applyManualLineDestAvailabilityRd(false);
api.applyManualLineRowDestinationRd();
const warm = {
    value: api.el(SELECT).value, saved: visible(SAVED), neu: visible(NEWF), toggle: visible(TOGGLE),
    detailLen: api.el(DETAIL).html.length,
};
check('warm order: the row destination is in the field', warm.value === '240', `value="${warm.value}"`);
check('warm order: the saved half is the one on screen', warm.saved === true && warm.neu === false);
check('warm order: the saved/new choice is offered', warm.toggle === true);
check('BOTH ORDERS END IDENTICALLY (the whole point of the fix)',
    JSON.stringify(cold) === JSON.stringify(warm), JSON.stringify(cold));

// and the same must hold when the company DOES have saved destinations
openFresh();
api.setDestHasSaved(true);
api.applyManualLineRowDestinationRd();
api.applyManualLineDestAvailabilityRd(true);
check('a company WITH saved destinations reaches the same state too',
    api.el(SELECT).value === '240' && visible(SAVED) && !visible(NEWF) && visible(TOGGLE));

/* ================================================================ */
console.log('\n=== (b) the pinned is_saved = 0 destination is what sits in the select ===');

openFresh();
api.applyManualLineRowDestinationRd();
const pinCall = api.getInitCalls().find((c) => c.selector === SELECT && c.options && c.options.pinnedOption);
check('the field is given a pinnedOption rather than a hand-built option', !!pinCall);
check('pinned id is the row own destination id', pinCall && pinCall.options.pinnedOption.id === 240);
check('pinned option opens selected', pinCall && pinCall.options.pinnedOption.selected === true);
check('pinned option carries the account data (so re-picking it can describe it again)',
    !!(pinCall && pinCall.options.pinnedOption.data && pinCall.options.pinnedOption.data.account_no_masked === 'XXXXXX7890'));
check('the field value is the ad-hoc destination id', api.el(SELECT).value === '240');
check('an is_saved = 0 row is pinned exactly like a saved one (no special-casing by is_saved)',
    api.getRowDestination().is_saved === 0 && api.el(SELECT).value === '240');

api.clearManualLineRowDestinationRd();
check('moving to another line drops the pin', api.getRowDestination() === null);
const clearCall = api.getInitCalls().filter((c) => c.options && c.options.pinnedOption === null).pop();
check('dropping the pin goes through initSelect2 too (pinnedOption: null)', !!clearCall);

/* ================================================================ */
console.log('\n=== (c) the summary under the picker is filled BY PREFILL, not by select2:select ===');

openFresh();
api.applyManualLineRowDestinationRd();
const html = api.el(DETAIL).html;
check('detail box is not empty after prefill (detailLen > 0)', html.length > 0, `len=${html.length}`);
check('it renders through the shared payee-detail component', html.indexOf('class="payee-detail"') !== -1);
check('it names the account', html.indexOf('กรมบังคับคดี') !== -1);
check('it shows bank, masked number and branch', html.indexOf('ธนาคารกรุงไทย') !== -1 && html.indexOf('XXXXXX7890') !== -1 && html.indexOf('สวนหลวง') !== -1);
check('it never prints an unmasked account number', html.indexOf('1234567890') === -1);

/* ================================================================ */
console.log('\n=== (d) clearing the picker gives the account fields back ===');

openFresh();
api.applyManualLineRowDestinationRd();
check('precondition: new fields hidden while a destination is selected', !visible(NEWF));
// what the select2:clear handler does, in its own order
api.el(DETAIL).html = '';
api.syncManualLineDestModeToggleRd();
api.setManualLineDestModeRd('new');
check('clear: the 4 account fields are visible', visible(NEWF) === true);
check('clear: the saved half is hidden', visible(SAVED) === false);
check('clear: the summary box is empty', api.el(DETAIL).html === '');
check('clear: the saved/new choice stays offered, so "saved" can be chosen again', visible(TOGGLE) === true);
check('clear: the row destination stays pinned, so the SAME destination can be re-selected',
    api.manualLineHasPinnedDestinationRd() === true);

/* ================================================================ */
console.log('\n=== round 3: the OTHER 2 payee pickers get the same treatment ===');

const PAYEE_SELECT = '#manualLinePayeeEmployee';
const PAYEE_DETAIL = '#manualLinePayeeEmployeeDetail';
const BANK_SELECT = '#manualLineBankAccount';
const BANK_DETAIL = '#manualLineBankAccountDetail';

const PAYEE_ROW = {
    id: 499,
    text: 'CEO - กฤษดา สาธุกิจชัย',
    data: {
        account_name: 'กฤษดา สาธุกิจชัย', bank_name_th: 'ธนาคารกสิกรไทย', bank_name_en: 'Kasikornbank',
        bank_branch: 'สีลม', account_no_masked: 'XXXXXX4321', has_bank_account: true,
    },
};
const BANK_ROW = {
    id: 4,
    text: 'ธนาคารไทยพาณิชย์ • XXXXXX7890 (Trandar)',
    data: {
        account_name: 'Trandar', bank_name_th: 'ธนาคารไทยพาณิชย์', bank_name_en: 'Siam Commercial Bank',
        bank_branch: 'สำนักงานใหญ่', account_no_masked: 'XXXXXX7890',
    },
};

// --- payee employee ---
api.resetDom();
api.setRowPayeeEmployee(JSON.parse(JSON.stringify(PAYEE_ROW)));
api.applyManualLineRowPayeeEmployeeRd();
check('employee: the row option is pinned and selected', api.el(PAYEE_SELECT).value === '499');
const payeePin = api.getInitCalls().find((c) => c.selector === PAYEE_SELECT && c.options && c.options.pinnedOption);
check('employee: it goes through initSelect2 pinnedOption, not a hand-built option', !!payeePin);
check('employee: the label is the endpoint own "EM - name" text, not the employee_no alone',
    payeePin && payeePin.options.pinnedOption.text === 'CEO - กฤษดา สาธุกิจชัย', payeePin && payeePin.options.pinnedOption.text);
check('employee: the summary box is filled by prefill', api.el(PAYEE_DETAIL).html.length > 0, `len=${api.el(PAYEE_DETAIL).html.length}`);
check('employee: the summary shows bank + masked number', api.el(PAYEE_DETAIL).html.indexOf('XXXXXX4321') !== -1 && api.el(PAYEE_DETAIL).html.indexOf('ธนาคารกสิกรไทย') !== -1);
check('employee: a prefilled row is not flagged as having no account', api.isPayeeBlocked() === false);

// the "no bank account on file" state has to reach a prefilled row exactly as a hand-picked one
api.resetDom();
api.setRowPayeeEmployee({ id: 500, text: 'EM500 - ไม่มีบัญชี', data: { has_bank_account: false } });
api.applyManualLineRowPayeeEmployeeRd();
check('employee: a payee with no account on file blocks the save on prefill too', api.isPayeeBlocked() === true);
check('employee: and says so in the box', api.el(PAYEE_DETAIL).html.indexOf('payee_employee_no_bank_account') !== -1);

// clear keeps the pin (so the same payee can be chosen again) and empties the summary
api.renderManualLinePayeeEmployeeDetailRd(null);
check('employee: clear empties the summary', api.el(PAYEE_DETAIL).html === '');
check('employee: clear un-blocks', api.isPayeeBlocked() === false);
check('employee: clear keeps the row pin', api.getRowPayeeEmployee() !== null);

// --- company bank account, both response orders ---
api.resetDom();
api.setCannedBankAccounts([{ id: 9, is_default: true, text_th: 'บัญชีหลัก', text_en: 'Primary', account_name: 'Primary', account_no_masked: 'XXXXXX0000' }]);
api.setRowBankAccount(JSON.parse(JSON.stringify(BANK_ROW)));
api.applyManualLineRowBankAccountRd();                       // prefill first
api.applyDefaultCompanyBankAccount(BANK_SELECT, BANK_DETAIL); // the default lookup lands after
const bankAfterLate = { value: api.el(BANK_SELECT).value, html: api.el(BANK_DETAIL).html };
check('bank: a late default lookup does NOT replace the row own account', bankAfterLate.value === '4', `value="${bankAfterLate.value}"`);
check('bank: nor its summary', bankAfterLate.html.indexOf('XXXXXX7890') !== -1 && bankAfterLate.html.indexOf('XXXXXX0000') === -1);

api.resetDom();
api.setCannedBankAccounts([{ id: 9, is_default: true, text_th: 'บัญชีหลัก', text_en: 'Primary', account_name: 'Primary', account_no_masked: 'XXXXXX0000' }]);
api.setRowBankAccount(JSON.parse(JSON.stringify(BANK_ROW)));
api.applyDefaultCompanyBankAccount(BANK_SELECT, BANK_DETAIL); // the default lands FIRST
api.applyManualLineRowBankAccountRd();                        // prefill after
const bankAfterEarly = { value: api.el(BANK_SELECT).value, html: api.el(BANK_DETAIL).html };
check('bank: an early default lookup is overridden by the row own account', bankAfterEarly.value === '4', `value="${bankAfterEarly.value}"`);
check('bank: and the summary is the row account, not the default', bankAfterEarly.html.indexOf('XXXXXX7890') !== -1 && bankAfterEarly.html.indexOf('XXXXXX0000') === -1);
check('bank: BOTH ORDERS END IDENTICALLY', JSON.stringify(bankAfterLate) === JSON.stringify(bankAfterEarly));

const bankPin = api.getInitCalls().find((c) => c.selector === BANK_SELECT && c.options && c.options.pinnedOption);
check('bank: the label is the endpoint own "bank • masked (name)" text', bankPin && bankPin.options.pinnedOption.text === BANK_ROW.text, bankPin && bankPin.options.pinnedOption.text);
api.renderManualLineBankAccountDetailRd(null);
check('bank: clear empties the summary', api.el(BANK_DETAIL).html === '');
check('bank: clear keeps the row pin', api.getRowBankAccount() !== null);

// --- the label follows the language, and never falls back to a re-composed string ---
api.setLang('en');
check('label picks the en text when the page is in English',
    api.manualLineRowLabelRd('ไทย', 'English', 'fallback') === 'English');
api.setLang('th');
check('label picks the th text when the page is in Thai',
    api.manualLineRowLabelRd('ไทย', 'English', 'fallback') === 'ไทย');
check('label falls back only when the payload carries neither',
    api.manualLineRowLabelRd(null, null, '#42') === '#42');

// --- moving to another line drops all 3 pins ---
api.setRowDestination({ id: 1, text: 'd', data: {} });
api.setRowPayeeEmployee({ id: 2, text: 'e', data: {} });
api.setRowBankAccount({ id: 3, text: 'b', data: {} });
api.clearManualLineRowDestinationRd();
check('all 3 pins are dropped together when the form moves on',
    api.getRowDestination() === null && api.getRowPayeeEmployee() === null && api.getRowBankAccount() === null);

/* ================================================================ */
console.log('\n=== round 3b: the employee option shows the NAME, the code stays searchable ===');

const splitSrc = fn(appSource, 'splitOptionCodePrefix');
const splitOptionCodePrefix = new Function(splitSrc + '; return splitOptionCodePrefix;')();

check("dash style takes 'CODE - Name' apart", JSON.stringify(splitOptionCodePrefix('CEO - กฤษดา สาธุกิจชัย', 'dash')) === JSON.stringify({ code: 'CEO', text: 'กฤษดา สาธุกิจชัย' }));
check('the code is kept aside (for the option title), not thrown away', splitOptionCodePrefix('EM001 - สมชาย ใจดี', 'dash').code === 'EM001');
check('a name that itself contains " - " survives (split on the FIRST separator only)',
    splitOptionCodePrefix('EM002 - บริษัท เอ - บี จำกัด', 'dash').text === 'บริษัท เอ - บี จำกัด');
check('a label with no code comes back untouched', splitOptionCodePrefix('ไม่มีรหัส', 'dash').text === 'ไม่มีรหัส');
check('the bracket shape is unchanged by the new style', JSON.stringify(splitOptionCodePrefix('[OT] ค่าล่วงเวลา', true)) === JSON.stringify({ code: 'OT', text: 'ค่าล่วงเวลา' }));
check('WITHOUT a style, a dash label is left alone (the catalog picker must not change)',
    splitOptionCodePrefix('CEO - กฤษดา', undefined).text === 'CEO - กฤษดา');

const inputStripped = stripComments(inputSource);
check('the picker passes its own style through to the splitter in processResults',
    inputStripped.indexOf('splitOptionCodePrefix(text, opts.stripCodePrefix)') !== -1);
check('...and in the template used for BOTH the dropdown row and the closed box (so a pinned option is stripped too)',
    inputStripped.indexOf('splitOptionCodePrefix(raw, opts.stripCodePrefix)') !== -1);
const detailStripped = stripComments(detailSource);
check("the payee employee picker asks for the 'dash' strip",
    /initSelect2\('#manualLinePayeeEmployee',\s*\{[^}]*stripCodePrefix:\s*'dash'/.test(detailStripped));
check('the catalog item picker keeps the bracket strip it already had',
    /initSelect2\('#manualLineItemSelect'[\s\S]{0,300}stripCodePrefix:\s*true/.test(detailStripped));
check('no client-side matcher was added -- searching stays server-side (R1b rule)',
    detailStripped.indexOf('matcher:') === -1);

// the slip row shows the name, by language, and falls back rather than going blank
const nameFnSrc = fn(detailSource, 'manualLinePayeeNameRd');
const nameApi = new Function('currentLang', 'splitOptionCodePrefix', 'manualLineRowLabelRd',
    nameFnSrc + '; return manualLinePayeeNameRd;');
const rowLabelFn = new Function('currentLang', fn(detailSource, 'manualLineRowLabelRd') + '; return manualLineRowLabelRd;');
const nameTh = nameApi('th', splitOptionCodePrefix, rowLabelFn('th'));
const nameEn = nameApi('en', splitOptionCodePrefix, rowLabelFn('en'));
const LINE_WITH_LABELS = { payee_employee_id: 499, payee_employee_no: 'CEO', payee_employee_label_th: 'CEO - กฤษดา สาธุกิจชัย', payee_employee_label_en: 'CEO - Kritsada Satukitchai' };
check('slip row: th shows the Thai name with no code', nameTh(LINE_WITH_LABELS) === 'กฤษดา สาธุกิจชัย', nameTh(LINE_WITH_LABELS));
check('slip row: en shows the English name with no code', nameEn(LINE_WITH_LABELS) === 'Kritsada Satukitchai', nameEn(LINE_WITH_LABELS));
check('slip row: no label at all falls back to the employee_no, never to a blank',
    nameTh({ payee_employee_id: 7, payee_employee_no: 'EM007' }) === 'EM007');
check('slip row: no label and no employee_no falls back to the id',
    nameTh({ payee_employee_id: 7 }) === '#7');

console.log('\n=== wiring: the real handlers call the real helpers ===');
const clearHandler = stripComments(handler(detailSource, 'select2:clear', SELECT));
check('select2:clear empties the summary box', clearHandler.indexOf(`$('${DETAIL}').empty()`) !== -1);
check('select2:clear switches to the new-account half', clearHandler.indexOf("setManualLineDestModeRd('new')") !== -1);
check('select2:clear re-evaluates whether the choice is offered', clearHandler.indexOf('syncManualLineDestModeToggleRd()') !== -1);
const prefillSrc = stripComments(fn(detailSource, 'prefillManualLineFormRd'));
check('prefill no longer hand-builds the destination option', prefillSrc.indexOf("$('#manualLineDestinationSelect').empty()") === -1);
check('prefill sets the row destination BEFORE it touches the payee choice',
    prefillSrc.indexOf('manualLineRowDestinationRd =') < prefillSrc.indexOf("setPayeeDestination('manualLine'"));
check('prefill reads the masked number from the payload, never a raw one',
    prefillSrc.indexOf('line.destination_account_no_masked') !== -1 && prefillSrc.indexOf('line.destination_account_no') === prefillSrc.indexOf('line.destination_account_no_masked'));
check('the availability step no longer forces a mode while a row destination is pinned',
    stripComments(fn(detailSource, 'applyManualLineDestAvailabilityRd')).indexOf('manualLineHasPinnedDestinationRd()') !== -1);
check('initSelect2 supports a pinned option that opens selected (input.js)',
    inputSource.indexOf('opts.pinnedOption.selected') !== -1);
check('initSelect2 does NOT remember `selected` (a re-init must not re-assert an old value)',
    stripComments(inputSource).indexOf('delete remembered.pinnedOption.selected') !== -1);
check('a pinned option can carry data onto its select2 option',
    inputSource.indexOf('$.extend({}, opts.pinnedOption.data, {') !== -1);

console.log(`\nPassed: ${passed}, Failed: ${failed}`);
if (failed > 0) { console.log('SOME TESTS FAILED'); process.exit(1); } else { console.log('ALL TESTS PASSED'); }
