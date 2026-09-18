/**
 * The Recurring Deduction Destination tab's TWO lists (public/js/payroll/detail.js, 2026-09-18
 * tiny-L3): the editable recurring cards it always had, and the read-only rows added underneath for
 * the per-installment assignments (employee_earning_deductions) whose destination is set on Employee
 * Detail.
 *
 * Reported for real: an employee with 4 EED deductions in the Breakdown saw a completely empty tab.
 * What this file holds in place:
 *  - both groups render from ONE payload, into their own containers;
 *  - the read-only group carries no control of any kind, and nothing it renders can reach the tab's
 *    dirty-guard scope (#recurringDestEditorCard) or the footer Save button;
 *  - the inline empty line is the TAB's, not the editable list's: it appears only when BOTH groups
 *    are empty;
 *  - a language switch re-renders the read-only rows from the rows already in hand -- no second
 *    request, and no re-init of the editor card's Select2s (the bug pattern tiny-L2 dealt with).
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
const formatJsPath = path.join(__dirname, '..', 'public', 'js', 'format-helpers.js');
const detailSource = fs.readFileSync(detailJsPath, 'utf8');
const appSource = fs.readFileSync(appJsPath, 'utf8');
const formatSource = fs.readFileSync(formatJsPath, 'utf8');

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
function decl(text, name) {
    const idx = text.indexOf(`const ${name} = {`);
    if (idx === -1) throw new Error(`${name} not found -- renamed/removed?`);
    return sliceBalanced(text, idx, name) + ';';
}
// A one-line `const NAME = ...;` -- sliceBalanced() needs a brace to balance, and a string
// constant has none. Same rule as every other extractor here: read from the real file.
function lineDecl(text, name) {
    const m = text.match(new RegExp(`^const ${name} = .*;$`, 'm'));
    if (!m) throw new Error(`${name} not found -- renamed/removed?`);
    return m[0];
}

/* ---------- a selector-keyed fake DOM, just enough for these functions ----------
   Containment is modelled the way this tab is actually built: each id owns its own html, and
   $('#x').find(...) only ever sees what was rendered INTO #x. That is exactly the property the
   read-only rows depend on -- they live in #eedDestList, a sibling of the dirty-guard's own scope
   (#recurringDestEditorCard), so no markup they add can ever be snapshotted as form state. */
const stubs = `
const DOM = {};
function el(sel) {
    if (!DOM[sel]) DOM[sel] = { classes: new Set(), value: '', html: '', attrs: {}, props: {}, data: {} };
    return DOM[sel];
}
let initCalls = [];
let ajaxCalls = [];
let currentLang = 'th';
// Parses the controls out of a container's own html -- enough for snapshotFormState() to run against
// what a render actually produced, rather than against a hand-written list of fields.
function parseControls(html) {
    const out = [];
    const re = /<(input|select|textarea)\\b([^>]*)>/gi;
    let m;
    while ((m = re.exec(html)) !== null) {
        const attrs = {};
        const are = /([a-zA-Z-]+)\\s*=\\s*"([^"]*)"/g;
        let a;
        while ((a = are.exec(m[2])) !== null) attrs[a[1]] = a[2];
        out.push({ __el: true, tag: m[1].toLowerCase(), attrs: attrs });
    }
    return out;
}
function $(target) {
    if (target && target.__el) {
        const c = target;
        return {
            length: 1,
            is: (what) => (what === ':disabled' ? 'disabled' in c.attrs
                : what === ':checkbox' ? c.attrs.type === 'checkbox'
                : what === ':radio' ? c.attrs.type === 'radio'
                : what === ':checked' ? 'checked' in c.attrs : false),
            attr: (k) => c.attrs[k],
            val: () => c.attrs.value || '',
        };
    }
    const sel = target;
    const e = el(sel);
    const api = {
        length: 1,
        hasClass: (c) => e.classes.has(c),
        addClass: (c) => { e.classes.add(c); return api; },
        removeClass: (c) => { e.classes.delete(c); return api; },
        prop: (k, v) => { if (v === undefined) return e.props[k]; e.props[k] = v; return api; },
        val: (v) => { if (v === undefined) return e.value; e.value = (v === null ? '' : v); return api; },
        html: (h) => { if (h === undefined) return e.html; e.html = h; return api; },
        text: () => api,
        trigger: () => api,
        attr: (k, v) => { if (v === undefined) return e.attrs[k]; e.attrs[k] = v; return api; },
        data: (k, v) => { if (v === undefined) return e.data[k]; e.data[k] = v; return api; },
        removeData: (k) => { delete e.data[k]; return api; },
        find: function () {
            const items = parseControls(e.html);
            return { length: items.length, each: function (cb) { items.forEach(function (it, i) { cb.call(it, i, it); }); } };
        },
    };
    return api;
}
$.getJSON = function (url, params, cb) { ajaxCalls.push({ url: url, params: params }); if (cb) cb(RESPONSE); };
$.extend = Object.assign;
// The real escapeHtml() (format-helpers.js) is a jQuery DOM round-trip, which a selector-keyed fake
// DOM cannot stand in for -- the same substitution tests/recurring_dest_prefill_test.js already makes.
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function escapeAttr(str) { return escapeHtml(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
function initSelect2(selector, options) { initCalls.push({ selector: selector, options: options }); }
function clearRecurringDestRowPinsRd() {}
function refreshAdjustmentTabDirtyGuard(paneId) { refreshDirtyGuardCalls.push(paneId); }
let refreshDirtyGuardCalls = [];
function refreshDirtyGuard(scope) { $(scope).data('dirtyGuardBaseline', snapshotFormState($(scope))); }
function recurringDestSaveBlockedRd() { return false; }
const BASE_URL = '';
const PAYROLL_RUN_ID = 752;
let manageLinesEmployeeId = 159;
let RESPONSE = { status: true, data: [], eed_rows: [] };
let langData = {};
function adjustmentActiveTabConfig() { return ADJUSTMENT_TAB_CONFIG_RD.manageLinesRecurringDestPane; }
function resetDom() { Object.keys(DOM).forEach((k) => delete DOM[k]); initCalls = []; ajaxCalls = []; refreshDirtyGuardCalls = []; }
`;

const extracted = stubs + '\n'
    + fn(formatSource, 'fmtNum') + '\n'
    + fn(appSource, 'snapshotFormState') + '\n'
    + fn(appSource, 'isFormDirty') + '\n'
    + fn(appSource, 'splitOptionCodePrefix') + '\n'
    + fn(detailSource, 'rowOptionLabelRd') + '\n'
    + fn(detailSource, 'payeeNameFromLabelRd') + '\n'
    + fn(detailSource, 'manualLinePayeeNameRd') + '\n'
    // 2026-09-18, tiny-L4: recurringDestPayeeSummary() is a thin wrapper over the shared
    // descriptor renderer now, so what this file really exercises is that renderer -- pulled in
    // from the real source like everything else here, never restated.
    + lineDecl(detailSource, 'PAYEE_DESCRIPTOR_SEP_RD') + '\n'
    + fn(detailSource, 'payeeDescriptorTextRd') + '\n'
    + fn(detailSource, 'recurringDestPayeeSummary') + '\n'
    + fn(detailSource, 'recurringDestRowHtml') + '\n'
    + fn(detailSource, 'eedDestRowHtml') + '\n'
    + fn(detailSource, 'eedDestGroupHtml') + '\n'
    + fn(detailSource, 'renderRecurringDestListsRd') + '\n'
    + fn(detailSource, 'refreshEedDestLanguageRd') + '\n'
    + fn(detailSource, 'loadRecurringDeductionDestinationsRd') + '\n'
    + decl(detailSource, 'ADJUSTMENT_TAB_CONFIG_RD') + '\n'
    + fn(detailSource, 'adjustmentTabIsDirty') + '\n'
    + fn(detailSource, 'refreshAdjustmentSaveButtonState') + '\n'
    + `module.exports = {
        el, resetDom,
        getInitCalls: () => initCalls,
        getAjaxCalls: () => ajaxCalls,
        setLang: (l) => { currentLang = l; },
        setLangData: (d) => { langData = d; },
        setRows: (rec, eed) => { recurringDestRows = rec; eedDestRows = eed; },
        setResponse: (r) => { RESPONSE = r; },
        getEedRows: () => eedDestRows,
        renderRecurringDestListsRd, refreshEedDestLanguageRd, loadRecurringDeductionDestinationsRd,
        adjustmentTabIsDirty, refreshAdjustmentSaveButtonState, refreshDirtyGuard,
        snapshotFormState, ADJUSTMENT_TAB_CONFIG_RD,
        $: $,
    };`;

const Module = require('module');
const m = new Module(detailJsPath);
m._compile(extracted, detailJsPath);
const api = m.exports;

const REC_LIST = '#recurringDestOverrideList';
const EED_LIST = '#eedDestList';
const CARD = '#recurringDestEditorCard';
const FOOTER_SAVE = '#btnSaveActiveAdjustmentTab';

const LANG = {
    recurring_dest_empty: 'ไม่มีรายการหักประจำที่ใช้งานสำหรับพนักงานคนนี้ในรอบนี้',
    recurring_dest_effective: 'กำลังส่งไปที่',
    recurring_dest_template_default: 'ค่าเริ่มต้นที่บันทึกไว้',
    recurring_dest_override: 'เปลี่ยนสำหรับรอบนี้',
    eed_dest_group_title: 'ปลายทางตามการตั้งค่าใน Employee Detail',
    eed_dest_open_employee: 'เปิดหน้าข้อมูลพนักงาน',
    eed_dest_installment: 'งวดที่ {no}/{total}',
    payee_dest_retained: 'จ่ายเข้าบริษัท',
    payee_type_company_unspecified: 'บัญชีบริษัท (ยังไม่ระบุ)',
    payee_type_not_disbursed: 'ไม่จ่ายออก',
};
const LANG_EN = {
    recurring_dest_empty: 'No recurring deductions active for this employee in this pay period.',
    recurring_dest_effective: 'Currently routed to',
    recurring_dest_template_default: 'Template default',
    recurring_dest_override: 'Override for this run',
    eed_dest_group_title: 'Destinations set on Employee Detail',
    eed_dest_open_employee: 'Open Employee Detail',
    eed_dest_installment: 'Installment {no}/{total}',
    payee_dest_retained: 'Retained by company',
    payee_type_company_unspecified: 'Company Account (not specified)',
    payee_type_not_disbursed: 'Not Disbursed',
};

let passed = 0;
let failed = 0;
function check(label, cond, detail) {
    if (cond) { passed++; console.log(`  PASS  ${label}${detail ? ' -- ' + detail : ''}`); }
    else { failed++; console.log(`  FAIL  ${label}${detail ? ' -- ' + detail : ''}`); }
}
function countOf(html, needle) { return html.split(needle).length - 1; }

// One EED row per destination kind, in the shape
// PayrollRunModel::earningDeductionDestinationsForEmployee() returns.
function eedRow(over) {
    return Object.assign({
        assignment_id: 1396,
        installment_id: 2437,
        item_code: 'UNIFORM_DEDUCT',
        item_name_th: 'หักค่าเครื่องแบบ',
        item_name_en: 'Uniform Deduction',
        item_type: 'deduction',
        calculation_method: 'manual_entry',
        installment_no: 1,
        total_installments: 2,
        is_installment_plan: true,
        amount: 5378.05,
        readonly: true,
        employee_detail_url: '/employees/159',
        destination: {
            payee_type: 'company',
            bank_account_id: 4,
            bank_account_label_th: 'ธนาคารกรุงศรีอยุธยา • XXXXXX5566 (Trandar)',
            bank_account_label_en: 'Bank of Ayudhya • XXXXXX5566 (Trandar)',
        },
    }, over || {});
}
const EED_EMPLOYEE = eedRow({
    assignment_id: 1398, item_code: 'EARLY_LEAVE_DEDUCT', item_name_th: 'หักกลับก่อนเวลา',
    item_name_en: 'Early Leave Deduction', amount: 2000, installment_no: 1, total_installments: 1,
    is_installment_plan: false,
    destination: {
        payee_type: 'employee', payee_employee_id: 499,
        payee_employee_label_th: 'CEO - กฤษดา สาธุกิจชัย', payee_employee_label_en: 'CEO - Kritsada Satukitchai',
    },
});
const EED_EXTERNAL = eedRow({
    assignment_id: 1399, item_code: 'LOAN_REPAY', item_name_th: 'หักเงินกู้ยืมพนักงาน',
    item_name_en: 'Loan Repayment', amount: 4000, installment_no: 1, total_installments: 1,
    is_installment_plan: false,
    destination: {
        payee_type: 'other_person', destination_id: 268,
        destination_label_th: 'กรมบังคับคดี (ธนาคารซีไอเอ็มบีไทย)', destination_label_en: 'กรมบังคับคดี (ธนาคารซีไอเอ็มบีไทย)',
    },
});
const REC_ROW = {
    recurring_id: 77,
    item_code: 'COOP_SAVE',
    item_name_th: 'หักสหกรณ์',
    item_name_en: 'Co-op Savings',
    template: { payee_type: 'company', bank_account_id: 4, bank_account_label_th: 'ธนาคารกรุงศรีอยุธยา • XXXXXX5566 (Trandar)', bank_account_label_en: 'Bank of Ayudhya • XXXXXX5566 (Trandar)' },
    override: null,
};

function renderWith(rec, eed) {
    api.resetDom();
    api.setLangData(LANG);
    api.setLang('th');
    api.setRows(rec, eed);
    api.renderRecurringDestListsRd();
}

/* ================================================================ */
console.log('=== (a) both groups render, from one payload ===');

renderWith([REC_ROW], [eedRow(), EED_EMPLOYEE, EED_EXTERNAL]);
const recHtml = api.el(REC_LIST).html;
const eedHtml = api.el(EED_LIST).html;
check('the editable group renders its recurring cards', recHtml.indexOf('data-recurring-id="77"') !== -1);
check('the read-only group renders one row per assignment', countOf(eedHtml, 'eed-dest-row') === 3,
    `${countOf(eedHtml, 'eed-dest-row')} row(s)`);
check('each read-only row is keyed by its assignment, not by a recurring id',
    countOf(eedHtml, 'data-assignment-id="1396"') === 1 && countOf(eedHtml, 'data-recurring-id=') === 0);
check('the read-only group says where its destinations are actually set',
    eedHtml.indexOf(LANG.eed_dest_group_title) !== -1);
check('...and links to that employee, opening in a new tab',
    eedHtml.indexOf('href="/employees/159"') !== -1 && eedHtml.indexOf('target="_blank"') !== -1);
check('the two groups are separate containers, not one merged list',
    recHtml.indexOf('eed-dest-row') === -1 && eedHtml.indexOf('data-recurring-id') === -1);

console.log('\n=== (b) every destination kind reads back through the shared summary ===');
check('a company-account row names WHICH account', eedHtml.indexOf('ธนาคารกรุงศรีอยุธยา • XXXXXX5566 (Trandar)') !== -1);
check('an employee-payee row names the payee', eedHtml.indexOf('กฤษดา สาธุกิจชัย') !== -1);
check('an external row names the destination', eedHtml.indexOf('กรมบังคับคดี (ธนาคารซีไอเอ็มบีไทย)') !== -1);
check('a multi-installment row says which installment this run pays',
    eedHtml.indexOf('งวดที่ 1/2') !== -1);
check('a single-installment row says nothing about installments',
    countOf(eedHtml, 'งวดที่') === 1, `${countOf(eedHtml, 'งวดที่')} installment line(s)`);
check('the amount is formatted by the shared number helper', eedHtml.indexOf('5,378.05') !== -1);

console.log('\n=== (c) nothing in the read-only group is a control ===');
['<input', '<select', '<textarea', '<button'].forEach(function (tag) {
    check(`no ${tag}> in the read-only group`, eedHtml.indexOf(tag) === -1);
});
check('no edit/reset handler hooks either',
    eedHtml.indexOf('btn-recurring-dest-edit') === -1 && eedHtml.indexOf('btn-recurring-dest-reset') === -1);
check('the editable group still has its own buttons (this test is not just finding an empty string)',
    recHtml.indexOf('btn-recurring-dest-edit') !== -1);
check('rules.md: no bg-light / bg-opacity-* utility on the read-only rows',
    eedHtml.indexOf('bg-light') === -1 && eedHtml.indexOf('bg-opacity') === -1);
check('...and no status colour on them either (§3: only an error may carry tone)',
    ['bg-warning', 'bg-success', 'bg-danger', 'text-warning', 'text-success', 'text-danger']
        .every((c) => eedHtml.indexOf(c) === -1));

console.log('\n=== (d) the read-only rows can never make the tab dirty ===');
const cfg = api.ADJUSTMENT_TAB_CONFIG_RD.manageLinesRecurringDestPane;
check('the dirty-guard scope is still the editor card alone', cfg.scope === CARD, cfg.scope);
check('...which is not where the read-only rows are rendered', cfg.scope !== EED_LIST);
// Baseline the (closed, empty) card, then render the read-only rows, then ask the 2 guards.
api.$(CARD).addClass('d-none');
api.refreshDirtyGuard(CARD);
const baselineBefore = api.$(CARD).data('dirtyGuardBaseline');
api.renderRecurringDestListsRd();
check('rendering them changes nothing in the guarded scope',
    api.snapshotFormState(api.$(CARD)) === baselineBefore, `"${baselineBefore}"`);
check('the tab is not dirty while the editor is closed', api.adjustmentTabIsDirty(cfg) === false);
api.refreshAdjustmentSaveButtonState();
check('the footer Save button stays disabled', api.el(FOOTER_SAVE).props.disabled === true);
// And with the editor OPEN but untouched: the read-only rows must not tip it into dirty either.
api.$(CARD).removeClass('d-none');
api.refreshDirtyGuard(CARD);
api.renderRecurringDestListsRd();
check('the same holds with the editor open and untouched', api.adjustmentTabIsDirty(cfg) === false);
api.refreshAdjustmentSaveButtonState();
check('...and the footer Save button is still disabled', api.el(FOOTER_SAVE).props.disabled === true);

console.log('\n=== (e) the empty line belongs to the TAB, not to the editable list ===');
renderWith([], []);
check('nothing at all: the inline empty line shows',
    api.el(REC_LIST).html.indexOf(LANG.recurring_dest_empty) !== -1);
check('...and the read-only container is emptied, not left stale', api.el(EED_LIST).html === '');

renderWith([], [eedRow()]);
check('read-only rows only: NO empty line',
    api.el(REC_LIST).html.indexOf(LANG.recurring_dest_empty) === -1 && api.el(REC_LIST).html === '');
check('...and the read-only rows are there', countOf(api.el(EED_LIST).html, 'eed-dest-row') === 1);

renderWith([REC_ROW], []);
check('editable cards only: no empty line, and no empty read-only heading',
    api.el(REC_LIST).html.indexOf(LANG.recurring_dest_empty) === -1 && api.el(EED_LIST).html === '');

console.log('\n=== (f) language switch re-labels the read-only rows in place ===');
renderWith([REC_ROW], [eedRow(), EED_EMPLOYEE, EED_EXTERNAL]);
const recHtmlBefore = api.el(REC_LIST).html;
const initCallsBefore = api.getInitCalls().length;
const ajaxBefore = api.getAjaxCalls().length;
api.setLang('en');
api.setLangData(LANG_EN);
api.refreshEedDestLanguageRd();
const eedEn = api.el(EED_LIST).html;
check('the item names switch language', eedEn.indexOf('Uniform Deduction') !== -1 && eedEn.indexOf('หักค่าเครื่องแบบ') === -1);
check('the destination labels switch too', eedEn.indexOf('Bank of Ayudhya • XXXXXX5566 (Trandar)') !== -1);
check('the group heading and its link switch',
    eedEn.indexOf(LANG_EN.eed_dest_group_title) !== -1 && eedEn.indexOf(LANG_EN.eed_dest_open_employee) !== -1);
check('the installment line switches', eedEn.indexOf('Installment 1/2') !== -1);
check('the editable cards are NOT re-rendered underneath the user', api.el(REC_LIST).html === recHtmlBefore);
check('no Select2 is re-initialised by this (the tiny-L2 bug pattern)',
    api.getInitCalls().length === initCallsBefore, `${api.getInitCalls().length - initCallsBefore} new init(s)`);
check('and no second request is made for data already in hand',
    api.getAjaxCalls().length === ajaxBefore, `${api.getAjaxCalls().length - ajaxBefore} new request(s)`);

console.log('\n=== (g) both groups come out of the ONE request the tab already made ===');
api.resetDom();
api.setLangData(LANG);
api.setLang('th');
api.setResponse({ status: true, data: [REC_ROW], eed_rows: [eedRow(), EED_EMPLOYEE] });
api.loadRecurringDeductionDestinationsRd();
check('exactly one request', api.getAjaxCalls().length === 1, `${api.getAjaxCalls().length} request(s)`);
check('...to the endpoint the tab already used',
    api.getAjaxCalls()[0].url.indexOf('/api/payroll-run.recurring-deduction-destinations-for-employee') !== -1);
check('its `data` fills the editable group', api.el(REC_LIST).html.indexOf('data-recurring-id="77"') !== -1);
check('its `eed_rows` fills the read-only group', countOf(api.el(EED_LIST).html, 'eed-dest-row') === 2);
check('a response without eed_rows at all degrades to an empty read-only group, not a crash',
    (function () {
        api.setResponse({ status: true, data: [REC_ROW] });
        api.loadRecurringDeductionDestinationsRd();
        return api.el(EED_LIST).html === '' && api.el(REC_LIST).html.indexOf('data-recurring-id="77"') !== -1;
    })());

console.log(`\nPassed: ${passed}, Failed: ${failed}`);
process.exit(failed === 0 ? 0 : 1);
