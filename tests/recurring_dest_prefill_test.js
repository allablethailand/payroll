/**
 * #recurringDestEditorCard -- the Override editor of the Recurring Deduction Destination tab, in
 * EDIT mode (public/js/payroll/detail.js, 2026-09-17 tiny-L2).
 *
 * The card had the same 3 faults the add/edit line form had before tiny-M, in all 3 of its pickers:
 *  (a) each row's current value was pushed in with a hand-built `new Option`, which applyLanguage()'s
 *      re-init drops -- and which spelled the value with whatever single field the payload carried,
 *      not with the label the picker itself shows;
 *  (b) an ad-hoc (is_saved = 0) destination has no option in payment-destination.options at all, so
 *      once cleared it could not be chosen again;
 *  (c) no summary box existed under any of the 3, because the only thing that ever filled one was a
 *      select2:select that programmatic selection never fires.
 * All 3 are answered the same way as in the line form, through the SAME helpers: the row's option is
 * pinned via initSelect2, and the summary is rendered by the shared renderer.
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
const detailSource = fs.readFileSync(detailJsPath, 'utf8');
const appSource = fs.readFileSync(appJsPath, 'utf8');

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
// A one-line `const NAME = ...;` -- fn()'s brace scan has nothing to balance in a string constant.
function lineDeclSrc(text, name) {
    const m = text.match(new RegExp(`^const ${name} = .*;$`, 'm'));
    if (!m) throw new Error(`${name} not found -- renamed/removed?`);
    return m[0];
}
function decl(text, name) {
    const idx = text.indexOf(`const ${name} = {`);
    if (idx === -1) throw new Error(`${name} not found -- renamed/removed?`);
    return sliceBalanced(text, idx, name) + ';';
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
    if (!DOM[sel]) DOM[sel] = { classes: new Set(), value: '', html: '', attrs: {}, props: {} };
    return DOM[sel];
}
let initCalls = [];
let currentLang = 'th';
function $(sel) {
    const e = el(sel);
    const api = {
        length: 1,
        hasClass: (c) => e.classes.has(c),
        addClass: (c) => { e.classes.add(c); return api; },
        removeClass: (c) => { e.classes.delete(c); return api; },
        toggleClass: (c, on) => { if (on) e.classes.add(c); else e.classes.delete(c); return api; },
        prop: (k, v) => { if (v === undefined) return e.props[k]; e.props[k] = v; return api; },
        val: (v) => { if (v === undefined) return e.value; e.value = (v === null ? '' : v); return api; },
        html: (h) => { if (h === undefined) return e.html; e.html = h; return api; },
        empty: () => { e.html = ''; e.value = ''; return api; },
        append: (opt) => { if (opt && opt.value !== undefined) e.value = String(opt.value); return api; },
        text: () => api,
        trigger: (ev) => { e.triggered = (e.triggered || []).concat([ev]); return api; },
        is: () => false,
        attr: (k, v) => { if (v === undefined) return e.attrs[k]; e.attrs[k] = v; return api; },
        data: () => undefined,
    };
    return api;
}
$.extend = Object.assign;
// applyFirstSavedDestinationDefault() builds a real <option>; here it is just a value carrier, so
// the test can watch that lookup put something in the field and prefill take it back.
function Option(text, value, defaultSelected, selected) { return { text: text, value: value, selected: selected }; }
function escapeHtml(str) { if (str === null || str === undefined) return ''; return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
// The real one is in input.js; here it records what a caller asked for and applies the one effect
// the functions under test depend on -- a pinned option that opens selected lands in the field.
function initSelect2(selector, options) {
    initCalls.push({ selector: selector, options: options });
    const p = options && options.pinnedOption;
    if (p && p.selected) el(selector).value = String(p.id);
    if (options && options.pinnedOption === null) el(selector).value = '';
    return undefined;
}
let payeeDestType = 'employee';
function payeeDestinationType() { return payeeDestType; }
const langData = {
    payee_employee_no_bank_account: 'This employee has no bank account on file yet',
    payee_dest_retained: 'Retained by company',
    payee_dest_employee: 'Transfer to another employee',
    payee_dest_external: 'Transfer to an external person or organization',
    payee_type_company_unspecified: 'Company Account (not specified)',
    payee_type_not_disbursed: 'Not Disbursed',
    // 2026-09-18, tiny-L4: recurringDestPayeeSummary() reads the shared descriptor's wording now
    payee_transfer_tag: 'Paid to',
    payee_bank_account_needs_review: 'Company Account -- bank account not specified, needs review',
    payee_dest_missing: 'This destination record no longer exists',
};
const BASE_URL = '';
// $.post is only reached by applyFirstSavedDestinationDefault(); the canned reply is set per test.
let cannedDestinations = [];
$.post = function (url, data, cb) { cb({ status: true, data: { items: cannedDestinations } }); return { fail: () => {} }; };
// The dirty-guard side of the footer button is not what this file is about: it is always "dirty",
// so anything that disables the button here is the tab's own blocked state, nothing else.
function adjustmentTabIsDirty() { return true; }
function adjustmentActiveTabConfig() { return ADJUSTMENT_TAB_CONFIG_RD.manageLinesRecurringDestPane; }
let recurringDestRowPinsRd = { payeeEmployee: null, bankAccount: null, destination: null };
let recurringDestPayeeEmployeeBlockedRd = false;
function resetDom() { Object.keys(DOM).forEach((k) => delete DOM[k]); initCalls = []; payeeDestType = 'employee'; }
`;

const extracted = stubs + '\n'
    + fn(appSource, 'payeeDetailHtml') + '\n'
    + fn(appSource, 'payeeDetailFromOption') + '\n'
    + fn(appSource, 'splitOptionCodePrefix') + '\n'
    + fn(appSource, 'applyFirstSavedDestinationDefault') + '\n'
    + fn(detailSource, 'rowOptionLabelRd') + '\n'
    + fn(detailSource, 'payeeNameFromLabelRd') + '\n'
    + fn(detailSource, 'payeeRowPinnedOptionsRd') + '\n'
    + fn(detailSource, 'pinRowOptionRd') + '\n'
    + fn(detailSource, 'unpinRowOptionRd') + '\n'
    + fn(detailSource, 'renderPayeeEmployeeDetailRd') + '\n'
    + fn(detailSource, 'renderPayeeAccountDetailRd') + '\n'
    + fn(detailSource, 'renderRecurringDestPayeeEmployeeDetailRd') + '\n'
    + fn(detailSource, 'recurringDestSaveBlockedRd') + '\n'
    + fn(detailSource, 'applyRecurringDestRowPinsRd') + '\n'
    + fn(detailSource, 'clearRecurringDestRowPinsRd') + '\n'
    + fn(detailSource, 'manualLinePayeeNameRd') + '\n'
    // 2026-09-18, tiny-L4: the 4 per-kind spellings moved out of recurringDestPayeeSummary() into
    // the one renderer every payee surface reads -- extracted from the real source, not restated.
    + lineDeclSrc(detailSource, 'PAYEE_DESCRIPTOR_SEP_RD') + '\n'
    + lineDeclSrc(detailSource, 'PAYEE_MASK_SHORT_RD') + '\n'
    + fn(detailSource, 'payeeDescriptorShortMaskRd') + '\n'
    + fn(detailSource, 'payeeDescriptorDropAccountNameRd') + '\n'
    + fn(detailSource, 'payeeDescriptorTextRd') + '\n'
    + fn(detailSource, 'recurringDestPayeeSummary') + '\n'
    + decl(detailSource, 'ADJUSTMENT_TAB_CONFIG_RD') + '\n'
    + fn(detailSource, 'refreshAdjustmentSaveButtonState') + '\n'
    + fn(detailSource, 'saveActiveAdjustmentTab') + '\n'
    + `module.exports = {
        el, resetDom,
        getInitCalls: () => initCalls,
        setPins: (p) => { recurringDestRowPinsRd = p; },
        getPins: () => recurringDestRowPinsRd,
        setPayeeDestType: (t) => { payeeDestType = t; },
        setCannedDestinations: (x) => { cannedDestinations = x; },
        setLang: (l) => { currentLang = l; },
        isBlocked: () => recurringDestPayeeEmployeeBlockedRd,
        payeeRowPinnedOptionsRd, applyRecurringDestRowPinsRd, clearRecurringDestRowPinsRd,
        renderRecurringDestPayeeEmployeeDetailRd, renderPayeeAccountDetailRd,
        recurringDestSaveBlockedRd, refreshAdjustmentSaveButtonState, saveActiveAdjustmentTab,
        recurringDestPayeeSummary, applyFirstSavedDestinationDefault,
        rowOptionLabelRd, payeeNameFromLabelRd,
    };`;

const Module = require('module');
const m = new Module(detailJsPath);
m._compile(extracted, detailJsPath);
const api = m.exports;

const EMP_SELECT = '#recurringDestPayeeEmployeeSelect';
const EMP_DETAIL = '#recurringDestPayeeEmployeeDetail';
const BANK_SELECT = '#recurringDestBankAccountSelect';
const BANK_DETAIL = '#recurringDestBankAccountDetail';
const DEST_SELECT = '#recurringDestDestinationSelect';
const DEST_DETAIL = '#recurringDestDestinationDetail';
const DEST_NEW = '#recurringDestDestinationNewFields';
const FOOTER_SAVE = '#btnSaveActiveAdjustmentTab';
const CARD = '#recurringDestEditorCard';
const IN_CARD_SAVE = '#btnSaveRecurringDestOverride';

let passed = 0;
let failed = 0;
function check(label, cond, detail) {
    if (cond) { passed++; console.log(`  PASS  ${label}${detail ? ' -- ' + detail : ''}`); }
    else { failed++; console.log(`  FAIL  ${label}${detail ? ' -- ' + detail : ''}`); }
}
const visible = (sel) => !api.el(sel).classes.has('d-none');

// One row per destination, in the shape PayrollRunModel::payeeDestinationDescriptor() returns.
const EMPLOYEE_ROW = {
    payee_type: 'employee',
    payee_employee_id: 499,
    payee_employee_label_th: 'CEO - กฤษดา สาธุกิจชัย',
    payee_employee_label_en: 'CEO - Kritsada Satukitchai',
    payee_employee_account_name: 'กฤษดา สาธุกิจชัย',
    payee_employee_bank_name_th: 'ธนาคารกสิกรไทย',
    payee_employee_bank_name_en: 'Kasikornbank',
    payee_employee_bank_branch: 'สีลม',
    payee_employee_account_no_masked: 'XXXXXX4321',
    payee_employee_has_bank_account: true,
    // 2026-09-18, tiny-L4: the payout account as ONE label, composed by PayrollCycleModel's own
    // composer inside payeeDestinationDescriptor() -- never glued together on this side.
    payee_employee_account_label_th: 'ธนาคารกสิกรไทย • XXXXXX4321 (กฤษดา สาธุกิจชัย)',
    payee_employee_account_label_en: 'Kasikornbank • XXXXXX4321 (กฤษดา สาธุกิจชัย)',
};
const COMPANY_ROW = {
    payee_type: 'company',
    bank_account_id: 4,
    bank_account_label_th: 'ธนาคารไทยพาณิชย์ • XXXXXX7890 (Trandar)',
    bank_account_label_en: 'Siam Commercial Bank • XXXXXX7890 (Trandar)',
    bank_account_name: 'Trandar',
    bank_account_bank_name_th: 'ธนาคารไทยพาณิชย์',
    bank_account_bank_name_en: 'Siam Commercial Bank',
    bank_account_branch: 'สำนักงานใหญ่',
    bank_account_no_masked: 'XXXXXX7890',
};
// is_saved = 0: the destination payment-destination.options can never return.
const EXTERNAL_ROW = {
    payee_type: 'other_person',
    destination_id: 240,
    destination_label_th: 'กรมบังคับคดี (ธนาคารกรุงไทย)',
    destination_label_en: 'กรมบังคับคดี (ธนาคารกรุงไทย)',
    destination_account_name: 'กรมบังคับคดี',
    destination_bank_name_th: 'ธนาคารกรุงไทย',
    destination_bank_name_en: 'Krungthai Bank',
    destination_bank_branch: 'สวนหลวง',
    destination_account_no_masked: 'XXXXXX7890',
    destination_is_saved: 0,
};
function openOn(row) {
    api.resetDom();
    api.setPins(api.payeeRowPinnedOptionsRd(JSON.parse(JSON.stringify(row))));
}

/* ================================================================ */
console.log('=== (a) each of the 3 destinations goes back in as a PINNED option ===');

openOn(EMPLOYEE_ROW);
api.setPayeeDestType('employee');
api.applyRecurringDestRowPinsRd();
const empPin = api.getInitCalls().find((c) => c.selector === EMP_SELECT && c.options && c.options.pinnedOption);
check('employee: the field is given a pinnedOption, not a hand-built new Option', !!empPin);
check('employee: it opens selected on the row own payee', api.el(EMP_SELECT).value === '499', `value="${api.el(EMP_SELECT).value}"`);
check('employee: the pinned label is the picker own "CODE - name", not the payload employee_no',
    empPin && empPin.options.pinnedOption.text === 'CEO - กฤษดา สาธุกิจชัย', empPin && empPin.options.pinnedOption.text);
check('employee: the option carries the account data, so re-picking it describes it again',
    !!(empPin && empPin.options.pinnedOption.data && empPin.options.pinnedOption.data.account_no_masked === 'XXXXXX4321'));

openOn(COMPANY_ROW);
api.setPayeeDestType('company');
api.applyRecurringDestRowPinsRd();
const bankPin = api.getInitCalls().find((c) => c.selector === BANK_SELECT && c.options && c.options.pinnedOption);
check('company: the row own account is pinned and selected', !!bankPin && api.el(BANK_SELECT).value === '4');
check('company: the pinned label is the picker own "bank • masked (name)", not the bare account_name',
    bankPin && bankPin.options.pinnedOption.text === COMPANY_ROW.bank_account_label_th, bankPin && bankPin.options.pinnedOption.text);

openOn(EXTERNAL_ROW);
api.setPayeeDestType('other_person');
api.applyRecurringDestRowPinsRd();
const destPin = api.getInitCalls().find((c) => c.selector === DEST_SELECT && c.options && c.options.pinnedOption);
check('external: the row own destination is pinned and selected', !!destPin && api.el(DEST_SELECT).value === '240');
check('external: an is_saved = 0 row is pinned exactly like a saved one (no special-casing)',
    api.getPins().destination.is_saved === 0 && api.el(DEST_SELECT).value === '240');
check('external: the pinned label is the endpoint own "name (bank)"',
    destPin && destPin.options.pinnedOption.text === EXTERNAL_ROW.destination_label_th, destPin && destPin.options.pinnedOption.text);
check('external: the new-account fields are hidden while a destination is selected', !visible(DEST_NEW));

/* ================================================================ */
console.log('\n=== (b) the summary box is filled BY PREFILL, not by select2:select ===');

openOn(EMPLOYEE_ROW);
api.applyRecurringDestRowPinsRd();
const empHtml = api.el(EMP_DETAIL).html;
check('employee: the box is not empty after prefill (detailLen > 0)', empHtml.length > 0, `len=${empHtml.length}`);
check('employee: it renders through the shared payee-detail component', empHtml.indexOf('class="payee-detail"') !== -1);
check('employee: bank, masked number and branch are all there',
    empHtml.indexOf('ธนาคารกสิกรไทย') !== -1 && empHtml.indexOf('XXXXXX4321') !== -1 && empHtml.indexOf('สีลม') !== -1);

openOn(COMPANY_ROW);
api.applyRecurringDestRowPinsRd();
check('company: the box is filled by prefill', api.el(BANK_DETAIL).html.indexOf('XXXXXX7890') !== -1);

openOn(EXTERNAL_ROW);
api.applyRecurringDestRowPinsRd();
const destHtml = api.el(DEST_DETAIL).html;
check('external: the box is filled by prefill', destHtml.length > 0, `len=${destHtml.length}`);
check('external: it names the account and its bank', destHtml.indexOf('กรมบังคับคดี') !== -1 && destHtml.indexOf('ธนาคารกรุงไทย') !== -1);
check('external: it never prints an unmasked account number', destHtml.indexOf('1234567890') === -1 && destHtml.indexOf('XXXXXX7890') !== -1);

/* ================================================================ */
console.log('\n=== (c) the async saved-destination default never wins over the row own value ===');

const CANNED = [{ id: 9, text_th: 'บัญชีอื่น', text_en: 'Another', account_name: 'Another', account_no_masked: 'XXXXXX0000' }];

// ORDER 1 -- the default lookup answers AFTER prefill has put the row's destination in.
openOn(EXTERNAL_ROW);
api.setCannedDestinations(CANNED);
api.applyRecurringDestRowPinsRd();
api.applyFirstSavedDestinationDefault(DEST_SELECT, DEST_NEW);
const late = { value: api.el(DEST_SELECT).value, detailLen: api.el(DEST_DETAIL).html.length, newFields: visible(DEST_NEW) };
check('late default: the row destination is still in the field', late.value === '240', `value="${late.value}"`);

// ORDER 2 -- the same lookup lands BEFORE prefill gets to the field.
openOn(EXTERNAL_ROW);
api.setCannedDestinations(CANNED);
api.applyFirstSavedDestinationDefault(DEST_SELECT, DEST_NEW);
api.applyRecurringDestRowPinsRd();
const early = { value: api.el(DEST_SELECT).value, detailLen: api.el(DEST_DETAIL).html.length, newFields: visible(DEST_NEW) };
check('early default: the row own destination overrides what the default picked', early.value === '240', `value="${early.value}"`);
check('BOTH ORDERS END IDENTICALLY (value, summary length, account fields)',
    JSON.stringify(late) === JSON.stringify(early), JSON.stringify(late));
check('neither order leaves the other destination summary on screen',
    api.el(DEST_DETAIL).html.indexOf('XXXXXX0000') === -1);

/* ================================================================ */
console.log('\n=== (d) clearing a picker empties its box and KEEPS the pin ===');

openOn(EXTERNAL_ROW);
api.applyRecurringDestRowPinsRd();
// what the select2:clear handler does, in its own order
api.el(DEST_NEW).classes.delete('d-none');
api.renderPayeeAccountDetailRd(DEST_DETAIL, null);
check('external clear: the summary box is empty', api.el(DEST_DETAIL).html === '');
check('external clear: the 4 account fields come back', visible(DEST_NEW) === true);
check('external clear: the row destination stays pinned, so the SAME one can be chosen again',
    api.getPins().destination !== null && api.getPins().destination.id === 240);

openOn(EMPLOYEE_ROW);
api.applyRecurringDestRowPinsRd();
api.renderRecurringDestPayeeEmployeeDetailRd(null);
check('employee clear: the summary box is empty', api.el(EMP_DETAIL).html === '');
check('employee clear: the row payee stays pinned', api.getPins().payeeEmployee !== null);

/* ================================================================ */
console.log('\n=== (e) a payee with no bank account on file blocks the save ===');

api.resetDom();
api.setPayeeDestType('employee');
api.setPins(api.payeeRowPinnedOptionsRd({
    payee_type: 'employee', payee_employee_id: 500,
    payee_employee_label_th: 'EM500 - ไม่มีบัญชี', payee_employee_label_en: 'EM500 - No account',
    payee_employee_has_bank_account: false,
}));
api.applyRecurringDestRowPinsRd();
check('the box says the account is missing', api.el(EMP_DETAIL).html.indexOf('payee_employee_no_bank_account') !== -1);
check('the card reports itself blocked', api.recurringDestSaveBlockedRd() === true);
check("the footer's Save is disabled", api.el(FOOTER_SAVE).props.disabled === true);
check('...and says why on hover', api.el(FOOTER_SAVE).attrs.title === 'This employee has no bank account on file yet');
// The footer dispatches with .trigger('click'), which fires handlers even on a DISABLED button --
// so the block has to be re-checked there, not left to the button's own disabled state.
api.saveActiveAdjustmentTab();
check('the dispatcher never reaches the card own save button while blocked',
    (api.el(IN_CARD_SAVE).triggered || []).length === 0);

// the same employee, but on a card whose payee choice is no longer "transfer to an employee"
api.setPayeeDestType('company');
check('a blocked payee does not block a card that is no longer routing to an employee',
    api.recurringDestSaveBlockedRd() === false);
api.setPayeeDestType('employee');
// ...and not once the card is closed either
api.el(CARD).classes.add('d-none');
check('a closed card is never blocked (nothing to save)', api.recurringDestSaveBlockedRd() === false);
api.el(CARD).classes.delete('d-none');

api.renderRecurringDestPayeeEmployeeDetailRd({ account_name: 'มีบัญชี', account_no_masked: 'XXXXXX1111', has_bank_account: true });
check('picking a payee who HAS an account unblocks it again', api.recurringDestSaveBlockedRd() === false);
check('...and the footer button follows', api.el(FOOTER_SAVE).props.disabled === false);
api.saveActiveAdjustmentTab();
check('...and the save it dispatches to is reached again',
    (api.el(IN_CARD_SAVE).triggered || []).indexOf('click') !== -1);

/* ================================================================ */
console.log('\n=== (f) moving to another row drops all 3 pins and all 3 boxes ===');

api.resetDom();
api.setPins({
    payeeEmployee: { id: 1, text: 'a', data: {} },
    bankAccount: { id: 2, text: 'b', data: {} },
    destination: { id: 3, text: 'c', data: {} },
});
api.applyRecurringDestRowPinsRd();
api.clearRecurringDestRowPinsRd();
const pins = api.getPins();
check('all 3 pins are dropped together', pins.payeeEmployee === null && pins.bankAccount === null && pins.destination === null);
const unpinCalls = api.getInitCalls().filter((c) => c.options && c.options.pinnedOption === null);
check('each drop goes through initSelect2 (pinnedOption: null), not a raw .empty()', unpinCalls.length === 3, `${unpinCalls.length} calls`);
check('the 3 summary boxes are emptied too',
    api.el(EMP_DETAIL).html === '' && api.el(BANK_DETAIL).html === '' && api.el(DEST_DETAIL).html === '');
check('the 3 fields are emptied with them', api.el(EMP_SELECT).value === '' && api.el(BANK_SELECT).value === '' && api.el(DEST_SELECT).value === '');

/* ================================================================ */
console.log('\n=== (g) the LIST line under the card names the payee the SAME way every other surface does ===');

/* 2026-09-18, tiny-L4: these used to pin 4 wordings only this card used. The card reads
   payeeDescriptorTextRd() now -- the one string the read-only slip and the hand-added lines also
   show -- so what is pinned here is that shared string, and that the ACCOUNT comes with it: a
   destination named without its account cannot be checked against a bank file. */
api.setLang('th');
check('list: an employee payee shows the name with its code stripped, and the account it is paid into',
    api.recurringDestPayeeSummary(EMPLOYEE_ROW) === 'Paid to กฤษดา สาธุกิจชัย • ธนาคารกสิกรไทย • XXXXXX4321 (กฤษดา สาธุกิจชัย)',
    api.recurringDestPayeeSummary(EMPLOYEE_ROW));
api.setLang('en');
check('list: and both halves follow the language',
    api.recurringDestPayeeSummary(EMPLOYEE_ROW) === 'Paid to Kritsada Satukitchai • Kasikornbank • XXXXXX4321 (กฤษดา สาธุกิจชัย)',
    api.recurringDestPayeeSummary(EMPLOYEE_ROW));
api.setLang('th');
check('list: a company payee names the account with the picker own label',
    // tiny-L6b (B4): the picker's label minus its trailing account-name parenthetical.
    api.recurringDestPayeeSummary(COMPANY_ROW) === `Retained by company • ${COMPANY_ROW.bank_account_label_th.replace(' (Trandar)', '')}`,
    api.recurringDestPayeeSummary(COMPANY_ROW));
check('list: an external payee says it is external AND which destination',
    api.recurringDestPayeeSummary(EXTERNAL_ROW) === `Transfer to an external person or organization • ${EXTERNAL_ROW.destination_label_th}`,
    api.recurringDestPayeeSummary(EXTERNAL_ROW));
check('list: a company payee with no account chosen reads as the review warning, never a silent blank',
    api.recurringDestPayeeSummary({ payee_type: 'company' }) === 'Company Account -- bank account not specified, needs review',
    api.recurringDestPayeeSummary({ payee_type: 'company' }));
check('list: no payee type at all still reads as retained by the company (this card own default)',
    api.recurringDestPayeeSummary({}) === 'Retained by company');
check('list: not_disbursed is unchanged', api.recurringDestPayeeSummary({ payee_type: 'not_disbursed' }) === 'Not Disbursed');
check('list: an employee payee with no label at all falls back down the same ladder the slip rows use',
    api.recurringDestPayeeSummary({ payee_type: 'employee', payee_employee_id: 7 }) === 'Paid to #7',
    api.recurringDestPayeeSummary({ payee_type: 'employee', payee_employee_id: 7 }));
check('list: a payee whose record is gone says so instead of trailing off after the name',
    api.recurringDestPayeeSummary({ payee_type: 'employee', payee_employee_id: 7, payee_employee_no: 'EM007', missing: true })
        === 'Paid to EM007 • This destination record no longer exists');
/* ================================================================ */
console.log('\n=== wiring: the real handler calls the real helpers ===');

const editHandler = stripComments(handler(detailSource, 'click', '.btn-recurring-dest-edit'));
check('the edit handler no longer hand-builds any option', editHandler.indexOf('new Option(') === -1);
check('it drops the previous row pins before doing anything else',
    editHandler.indexOf('clearRecurringDestRowPinsRd()') < editHandler.indexOf('payeeRowPinnedOptionsRd('));
check('it builds the pins BEFORE the payee choice (whose onChange starts the async default lookup)',
    editHandler.indexOf('recurringDestRowPinsRd = payeeRowPinnedOptionsRd(') < editHandler.indexOf('setRecurringDestPayeeType('));
check('and applies them AFTER it, so prefill is always last',
    editHandler.indexOf('applyRecurringDestRowPinsRd()') > editHandler.indexOf('setRecurringDestPayeeType('));
check('it reads the row through the shared template/override descriptor, not the old flat fields',
    editHandler.indexOf('row.template') !== -1 && editHandler.indexOf('row.template_payee_type') === -1);

const detailStripped = stripComments(detailSource);
check("the payee picker asks for the 'dash' strip, so no employee code is printed inline (rules.md §5/§6)",
    /initSelect2\('#recurringDestPayeeEmployeeSelect',\s*\{[^}]*stripCodePrefix:\s*'dash'/.test(detailStripped));
check('no client-side matcher was added -- searching by code stays server-side',
    detailStripped.indexOf('matcher:') === -1);
check('closing the card drops the pins too',
    stripComments(fn(detailSource, 'closeRecurringDestEditorRd')).indexOf('clearRecurringDestRowPinsRd()') !== -1);
const payloadSrc = stripComments(fn(detailSource, 'recurringDestFormPayloadRd'));
check('the payload builder refuses a payee with no bank account, in the card (§9), not in a dialog',
    payloadSrc.indexOf('recurringDestPayeeEmployeeBlockedRd') !== -1 && payloadSrc.indexOf('showWarning') === -1);
check('the footer button honours a tab that says it is blocked',
    stripComments(fn(detailSource, 'refreshAdjustmentSaveButtonState')).indexOf('cfg.blockedFn') !== -1);
check('...and so does the dispatcher behind it',
    stripComments(fn(detailSource, 'saveActiveAdjustmentTab')).indexOf('cfg.blockedFn') !== -1);
const clearDestHandler = stripComments(handler(detailSource, 'select2:clear', '#recurringDestDestinationSelect'));
check('select2:clear on the destination empties its box and brings the account fields back',
    clearDestHandler.indexOf('renderPayeeAccountDetailRd') !== -1 && clearDestHandler.indexOf("removeClass('d-none')") !== -1);
check('the 3 summary boxes exist in the markup, one per picker',
    ['recurringDestPayeeEmployeeDetail', 'recurringDestBankAccountDetail', 'recurringDestDestinationDetail']
        .every((id) => fs.readFileSync(path.join(__dirname, '..', 'app', 'views', 'payroll', 'detail.php'), 'utf8').indexOf(`id="${id}"`) !== -1));

console.log(`\nPassed: ${passed}, Failed: ${failed}`);
if (failed > 0) { console.log('SOME TESTS FAILED'); process.exit(1); } else { console.log('ALL TESTS PASSED'); }
