/**
 * #manageLinesModal ("ตั้งค่ารายบุคคล") -- the Recurring Deduction Destination tab's own dirty guard
 * and its editor-card refusals (public/js/payroll/detail.js).
 *
 * Written 2026-09-17 (tiny-L) to lock 2 real bugs that were found by reading, not by a failing test:
 *   (a) adjustmentTabIsDirty() ignored `activeOnly`, so pressing Cancel in the editor card (which
 *       only hid it) left the tab "dirty" forever -- the tab-switch guard and the modal-close guard
 *       both kept asking about changes the user had already abandoned.
 *   (b) 2 of the 3 refusals in #btnSaveRecurringDestOverride's handler did `return { ok: false, ... }`
 *       straight out of a jQuery click handler, where the returned object goes nowhere: choosing
 *       "transfer to an employee"/"retained by company" without picking the employee/account and
 *       pressing Save did NOTHING, silently. The third refusal used a centre-screen dialog, which
 *       §9 rules out for a form refusal inside a modal (it covers the values it is about).
 *
 * Same convention as this project's other tests/*.js (no framework, PASS/FAIL lines, nonzero exit on
 * failure) and the same real-source-extraction technique tests/user_agent_parser_test.js
 * established -- detail.js cannot be require()'d (top-level jQuery/DOM everywhere), so the functions
 * under test are extracted by name and compiled against minimal stand-ins.
 *
 * Scope: behaviour + wiring only. Whether the callout LOOKS right (border, dark mode, 430px width)
 * is verified against the live app with Playwright in the same round -- a string test can't see it.
 */
const fs = require('fs');
const path = require('path');

const detailJsPath = path.join(__dirname, '..', 'public', 'js', 'payroll', 'detail.js');
const appJsPath = path.join(__dirname, '..', 'public', 'js', 'app.js');
const detailSource = fs.readFileSync(detailJsPath, 'utf8');
const appSource = fs.readFileSync(appJsPath, 'utf8');

function sliceBalanced(fileText, startIdx, what) {
    const braceStart = fileText.indexOf('{', startIdx);
    let depth = 0;
    let i = braceStart;
    for (; i < fileText.length; i++) {
        if (fileText[i] === '{') depth++;
        else if (fileText[i] === '}') {
            depth--;
            if (depth === 0) break;
        }
    }
    if (depth !== 0) throw new Error(`${what} -- could not find a matching closing brace (naive scan failed)`);
    return fileText.slice(startIdx, i + 1);
}
function extractFunctionSource(fileText, fnName) {
    const marker = `function ${fnName}(`;
    const startIdx = fileText.indexOf(marker);
    if (startIdx === -1) throw new Error(`${fnName}() not found -- has it been renamed/removed?`);
    return sliceBalanced(fileText, startIdx, `${fnName}()`);
}
// The click handlers themselves can't be called from here (they are bound onto `document`), so the
// wiring assertions at the bottom read their source instead.
// The wiring assertions below must read CODE, not the comments around it -- a comment that explains
// the bug it fixed contains the very shape those assertions search for (this is not hypothetical:
// the `return { ok:` check failed on its own fix's docblock the first time it ran).
// Only whole comment lines and /* */ blocks are removed; nothing here has a `//` inside a string.
function stripComments(src) {
    return src.replace(/\/\*[\s\S]*?\*\//g, '').split('\n').filter((line) => !/^\s*\/\//.test(line)).join('\n');
}
function extractHandlerSource(fileText, selector) {
    const marker = `$(document).on('click', '${selector}'`;
    const startIdx = fileText.indexOf(marker);
    if (startIdx === -1) throw new Error(`click handler for ${selector} not found -- has it been renamed/removed?`);
    const lineEnd = fileText.indexOf('\n', startIdx);
    const firstLine = fileText.slice(startIdx, lineEnd);
    // A one-line handler (`..., closeRecurringDestEditorRd);`) has no block to balance.
    if (firstLine.indexOf('{') === -1) return firstLine;
    return sliceBalanced(fileText, startIdx, `handler(${selector})`);
}

/* ---------- stand-ins ---------- */
// A selector-keyed fake DOM: enough of jQuery for the 4 functions under test (classes, .val(),
// .is(':checked'), .data(), .html()/.empty()) and nothing more.
const stubs = `
const DOM = {};
function el(sel) {
    if (!DOM[sel]) DOM[sel] = { classes: new Set(), value: '', checked: false, data: {}, html: '' };
    return DOM[sel];
}
let calls = { isFormDirty: 0, refreshGuard: [], refreshSaveBtn: 0 };
function resetCalls() { calls = { isFormDirty: 0, refreshGuard: [], refreshSaveBtn: 0 }; }
function $(sel) {
    const e = el(sel);
    const api = {
        hasClass: (c) => e.classes.has(c),
        addClass: (c) => { String(c).split(' ').filter(Boolean).forEach((x) => e.classes.add(x)); return api; },
        removeClass: (c) => { String(c).split(' ').filter(Boolean).forEach((x) => e.classes.delete(x)); return api; },
        val: (v) => { if (v === undefined) return e.value; e.value = v; return api; },
        is: (what) => (what === ':checked' ? e.checked : false),
        data: (k) => e.data[k],
        html: (h) => { if (h === undefined) return e.html; e.html = h; return api; },
        empty: () => { e.html = ''; return api; },
    };
    return api;
}
// Deliberately always "dirty": every activeOnly assertion below has to prove the SHORT-CIRCUIT, not
// that the underlying comparison happened to agree.
let stubIsDirtyResult = true;
function isFormDirty($scope, baseline) { calls.isFormDirty++; return stubIsDirtyResult; }
function refreshAdjustmentTabDirtyGuard(paneId) { calls.refreshGuard.push(paneId); }
function refreshAdjustmentSaveButtonState() { calls.refreshSaveBtn++; }
let stubPayeeType = 'company';
function payeeDestinationType(prefix) { return stubPayeeType; }
function escapeHtml(str) { if (str === null || str === undefined) return ''; return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
const PAYROLL_RUN_ID = 4242;
let langData = {};
const ADJUSTMENT_TAB_CONFIG_RD = {
    manageLinesAttendancePane: { scope: '#manageLinesAttendancePane', saveSelector: '#btnSaveAttendanceData' },
    manageLinesRecurringDestPane: { scope: '#recurringDestEditorCard', saveSelector: '#btnSaveRecurringDestOverride', activeOnly: true },
    manageLinesCalcPane: { scope: '#manageLinesCalcPane', saveSelector: '#btnSaveEmpCalcOverride' },
};
`;

const extracted = stubs + '\n'
    + extractFunctionSource(appSource, 'calloutHtml') + '\n'
    + extractFunctionSource(detailSource, 'adjustmentTabIsDirty') + '\n'
    + extractFunctionSource(detailSource, 'closeRecurringDestEditorRd') + '\n'
    + extractFunctionSource(detailSource, 'recurringDestFormPayloadRd') + '\n'
    + extractFunctionSource(detailSource, 'formCalloutErrorRd') + '\n'
    + extractFunctionSource(detailSource, 'recurringDestFormErrorRd') + '\n'
    + `module.exports = {
        adjustmentTabIsDirty, closeRecurringDestEditorRd, recurringDestFormPayloadRd,
        recurringDestFormErrorRd, ADJUSTMENT_TAB_CONFIG_RD, el, resetCalls,
        getCalls: () => calls,
        setPayeeType: (t) => { stubPayeeType = t; },
        setLang: (l) => { langData = l; },
        setIsDirty: (v) => { stubIsDirtyResult = v; },
    };`;

const Module = require('module');
const m = new Module(detailJsPath);
m._compile(extracted, detailJsPath);
const {
    adjustmentTabIsDirty, closeRecurringDestEditorRd, recurringDestFormPayloadRd,
    recurringDestFormErrorRd, ADJUSTMENT_TAB_CONFIG_RD, el, resetCalls, getCalls,
    setPayeeType, setLang, setIsDirty,
} = m.exports;

const RECURRING_CFG = ADJUSTMENT_TAB_CONFIG_RD.manageLinesRecurringDestPane;
const ATTENDANCE_CFG = ADJUSTMENT_TAB_CONFIG_RD.manageLinesAttendancePane;
const CARD = '#recurringDestEditorCard';
const ERROR_BOX = '#recurringDestEditorError';

let passed = 0;
let failed = 0;
function check(label, cond) {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; console.log(`  FAIL  ${label}`); }
}
function openCard() { el(CARD).classes.delete('d-none'); }
function hideCard() { el(CARD).classes.add('d-none'); }

/* ================================================================ */
console.log('=== adjustmentTabIsDirty() -- activeOnly short-circuit (real bug, 2026-09-17) ===');

setIsDirty(true);
openCard();
resetCalls();
check('editor card OPEN + form dirty -> dirty', adjustmentTabIsDirty(RECURRING_CFG) === true);

hideCard();
resetCalls();
const hiddenResult = adjustmentTabIsDirty(RECURRING_CFG);
check('editor card HIDDEN -> not dirty, even though the form comparison would say dirty', hiddenResult === false);
check('editor card HIDDEN -> isFormDirty() is never even consulted (short-circuit, not agreement)', getCalls().isFormDirty === 0);

resetCalls();
el(ATTENDANCE_CFG.scope).classes.add('d-none');
check('a NON-activeOnly tab is unaffected: hidden scope still reports dirty', adjustmentTabIsDirty(ATTENDANCE_CFG) === true);
check('a NON-activeOnly tab still goes through isFormDirty()', getCalls().isFormDirty === 1);
el(ATTENDANCE_CFG.scope).classes.delete('d-none');

check('no config at all -> not dirty (unchanged)', adjustmentTabIsDirty(null) === false);
check('undefined config -> not dirty (unchanged)', adjustmentTabIsDirty(undefined) === false);

/* ================================================================ */
console.log('\n=== Cancel closes the editor for real (bug (a): Cancel used to only hide the card) ===');

setIsDirty(true);
openCard();
recurringDestFormErrorRd('a refusal from the row this card was opened on before');
resetCalls();
closeRecurringDestEditorRd();

check('Cancel hides the editor card', el(CARD).classes.has('d-none') === true);
check('Cancel re-baselines THIS tab (refreshAdjustmentTabDirtyGuard called with its own pane id)',
    getCalls().refreshGuard.length === 1 && getCalls().refreshGuard[0] === 'manageLinesRecurringDestPane');
check('Cancel refreshes the footer Save button state', getCalls().refreshSaveBtn === 1);
check('Cancel clears any refusal left in the card', el(ERROR_BOX).html === '' && el(ERROR_BOX).classes.has('d-none') === true);
check('after Cancel the tab is NOT dirty -> no confirm on tab switch / modal close', adjustmentTabIsDirty(RECURRING_CFG) === false);

/* ================================================================ */
console.log('\n=== recurringDestFormPayloadRd() -- refusals are RETURNED, never shown, never partial ===');

setLang({
    payee_employee_select_required: 'กรุณาเลือกพนักงานผู้รับโอน',
    bank_account_select_required: 'กรุณาเลือกบัญชีธนาคาร',
    destination_required_message: 'กรุณาเลือกปลายทางที่บันทึกไว้ หรือกรอกชื่อบัญชี เลขที่บัญชี และธนาคารให้ครบ',
});
el('#recurringDestEditorRecurringId').value = '77';

setPayeeType('employee');
el('#recurringDestPayeeEmployeeSelect').value = '';
const noEmployee = recurringDestFormPayloadRd();
check('payee=employee with nobody picked -> ok:false', noEmployee.ok === false);
check('payee=employee refusal carries the real message (not an empty/undefined string)', noEmployee.message === 'กรุณาเลือกพนักงานผู้รับโอน');
check('payee=employee refusal carries NO payload -- nothing for a caller to accidentally POST', noEmployee.payload === undefined);

el('#recurringDestPayeeEmployeeSelect').value = '901';
const withEmployee = recurringDestFormPayloadRd();
check('payee=employee with someone picked -> ok:true', withEmployee.ok === true);
check('payee=employee payload carries payee_employee_id + run id + recurring id',
    withEmployee.payload.payee_employee_id === '901' && withEmployee.payload.id === 4242 && withEmployee.payload.recurring_id === '77');

setPayeeType('company');
el('#recurringDestBankAccountSelect').value = '';
const noBank = recurringDestFormPayloadRd();
check('payee=company with no account picked -> ok:false', noBank.ok === false);
check('payee=company refusal reuses the existing bank_account_select_required copy', noBank.message === 'กรุณาเลือกบัญชีธนาคาร');
check('payee=company refusal carries NO payload', noBank.payload === undefined);

el('#recurringDestBankAccountSelect').value = '12';
const withBank = recurringDestFormPayloadRd();
check('payee=company with an account picked -> ok:true + bank_account_id', withBank.ok === true && withBank.payload.bank_account_id === '12');

setPayeeType('other_person');
el('#recurringDestDestinationSelect').value = '';
el('#recurringDestAccountName').value = '';
el('#recurringDestAccountNo').value = '';
el('#recurringDestBank').value = '';
const noDest = recurringDestFormPayloadRd();
check('payee=other_person with nothing filled -> ok:false', noDest.ok === false);
check('payee=other_person refusal is the SAME message it always showed, now returned not dialog-ed',
    noDest.message === 'กรุณาเลือกปลายทางที่บันทึกไว้ หรือกรอกชื่อบัญชี เลขที่บัญชี และธนาคารให้ครบ');

el('#recurringDestDestinationSelect').value = '55';
const savedDest = recurringDestFormPayloadRd();
check('payee=other_person with a saved destination -> ok:true + destination_id only',
    savedDest.ok === true && savedDest.payload.destination_id === '55' && savedDest.payload.account_name === undefined);

el('#recurringDestDestinationSelect').value = '';
el('#recurringDestAccountName').value = ' Somchai Jaidee ';
el('#recurringDestAccountNo').value = ' 1234567890 ';
el('#recurringDestBank').value = '4';
el('#recurringDestBankBranch').value = '  ';
el('#recurringDestSaveForReuse').checked = true;
const newDest = recurringDestFormPayloadRd();
check('payee=other_person, new account filled -> ok:true, fields trimmed',
    newDest.ok === true && newDest.payload.account_name === 'Somchai Jaidee' && newDest.payload.account_no === '1234567890');
check('payee=other_person, blank branch becomes undefined (not an empty string)', newDest.payload.bank_branch === undefined);
check('payee=other_person carries is_saved from the checkbox', newDest.payload.is_saved === true);

setPayeeType('not_disbursed');
const unreachableType = recurringDestFormPayloadRd();
check('an unrecognized payee_type still builds a payload (no branch = no extra field, unchanged)',
    unreachableType.ok === true && unreachableType.payload.payee_type === 'not_disbursed');

/* ================================================================ */
console.log('\n=== the refusal renders as a callout INSIDE the card (§9/§15), never a dialog ===');

recurringDestFormErrorRd('กรุณาเลือกบัญชีธนาคาร');
check('refusal renders through the shared callout component, danger tone', el(ERROR_BOX).html === '<div class="callout callout-danger">กรุณาเลือกบัญชีธนาคาร</div>');
check('refusal box is shown (d-none removed)', el(ERROR_BOX).classes.has('d-none') === false);

recurringDestFormErrorRd('<script>alert(1)</script>');
check('message text is escaped before it reaches the callout', el(ERROR_BOX).html.indexOf('<script>') === -1 && el(ERROR_BOX).html.indexOf('&lt;script&gt;') !== -1);

recurringDestFormErrorRd('');
check('empty message clears the box and hides it', el(ERROR_BOX).html === '' && el(ERROR_BOX).classes.has('d-none') === true);

/* ================================================================ */
console.log('\n=== wiring: the Save handler refuses BEFORE any request (bug (b)) ===');

const saveHandler = stripComments(extractHandlerSource(detailSource, '#btnSaveRecurringDestOverride'));
const cancelHandler = stripComments(extractHandlerSource(detailSource, '#btnCancelRecurringDestEdit'));

check('Save handler asks recurringDestFormPayloadRd() for the payload', saveHandler.indexOf('recurringDestFormPayloadRd()') !== -1);
check('Save handler shows the refusal in the form (recurringDestFormErrorRd)', saveHandler.indexOf('recurringDestFormErrorRd(built.message)') !== -1);
check('Save handler returns on a refusal BEFORE it reaches $.ajax(',
    saveHandler.indexOf('if (!built.ok)') !== -1
    && saveHandler.indexOf('if (!built.ok)') < saveHandler.indexOf('$.ajax(')
    && saveHandler.indexOf('return;') < saveHandler.indexOf('$.ajax('));
check('Save handler no longer returns a value out of a click handler (`return { ok:`, the silent bug)',
    saveHandler.indexOf('return { ok:') === -1);
check('Save handler shows no centre-screen dialog at all (§9: refusals belong in the form)',
    saveHandler.indexOf('showWarning(') === -1 && saveHandler.indexOf('showError(') === -1);
check('a server refusal keeps the card open and renders in the form too',
    saveHandler.indexOf('recurringDestFormErrorRd(res.message') !== -1);
check('Cancel is wired straight to closeRecurringDestEditorRd (no bare addClass in the handler)',
    cancelHandler.indexOf('closeRecurringDestEditorRd') !== -1 && cancelHandler.indexOf("addClass('d-none')") === -1);

/* ================================================================ */
console.log('\n=== the copy exists in BOTH language files (no fallback-only message) ===');

const th = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'public', 'lang', 'th.json'), 'utf8'));
const en = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'public', 'lang', 'en.json'), 'utf8'));
['payee_employee_select_required', 'bank_account_select_required', 'destination_required_message'].forEach(function (key) {
    check(`lang key ${key} exists in th.json and en.json`,
        typeof th[key] === 'string' && th[key].trim() !== '' && typeof en[key] === 'string' && en[key].trim() !== '');
});

/* ================================================================ */
console.log('\n=== the callout box exists in the view, inside the editor card ===');

const viewSource = fs.readFileSync(path.join(__dirname, '..', 'app', 'views', 'payroll', 'detail.php'), 'utf8');
const cardIdx = viewSource.indexOf('id="recurringDestEditorCard"');
const errIdx = viewSource.indexOf('id="recurringDestEditorError"');
const saveBtnIdx = viewSource.indexOf('id="btnSaveRecurringDestOverride"');
check('#recurringDestEditorError exists in payroll/detail.php', errIdx !== -1);
check('#recurringDestEditorError sits INSIDE #recurringDestEditorCard (between the card and its Save button)',
    cardIdx !== -1 && saveBtnIdx !== -1 && errIdx > cardIdx && errIdx < saveBtnIdx);
check('#recurringDestEditorError starts hidden', /id="recurringDestEditorError"[^>]*class="[^"]*d-none/.test(viewSource)
    || /class="[^"]*d-none[^"]*"[^>]*id="recurringDestEditorError"/.test(viewSource));

// The exact shape tests/run_all.php tallies -- anything else is counted as "no summary line".
console.log(`\nPassed: ${passed}, Failed: ${failed}`);
if (failed > 0) {
    console.log('SOME TESTS FAILED');
    process.exit(1);
} else {
    console.log('ALL TESTS PASSED');
}
