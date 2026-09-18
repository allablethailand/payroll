/**
 * 2026-09-18, 4a-1 ("สลิปเป็นที่เดียว") -- the read-only slip and the editable slip are ONE renderer.
 *
 * What is locked here is the thing the round exists for: given the same lines, the two modes render
 * the same rows, in the same order, with the same figures -- and 'view' differs ONLY by not
 * rendering the 2 columns it has no use for (never by hiding them, which is how a control nobody
 * may press stays one keyboard tab away).
 *
 * The one documented exception to byte-identity: a skipped row's reason badge. The editable slip
 * puts it in the toggle column (it is what stands in for the switch that row cannot have); the
 * read-only slip has no toggle column, so it goes after the name. Both are asserted explicitly.
 *
 * Scope: markup and text only. Colour/spacing/430px are checked against the live app with
 * Playwright in the same round -- a string test cannot see those.
 */
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const detailJsPath = path.join(root, 'public', 'js', 'payroll', 'detail.js');
const detailSource = fs.readFileSync(detailJsPath, 'utf8');
const formatSource = fs.readFileSync(path.join(root, 'public', 'js', 'format-helpers.js'), 'utf8');
const LANG = JSON.parse(fs.readFileSync(path.join(root, 'public', 'lang', 'th.json'), 'utf8'));

function fn(text, name) {
    const startIdx = text.indexOf(`function ${name}(`);
    if (startIdx === -1) throw new Error(`${name}() not found -- renamed/removed?`);
    let depth = 0;
    let i = text.indexOf('{', startIdx);
    for (; i < text.length; i++) {
        if (text[i] === '{') depth++;
        else if (text[i] === '}' && --depth === 0) break;
    }
    if (depth !== 0) throw new Error(`${name}() -- no matching closing brace`);
    return text.slice(startIdx, i + 1);
}
function constDecl(text, name) {
    const m = text.match(new RegExp(`^const ${name} = .*;$`, 'm'));
    if (!m) throw new Error(`${name} not found -- renamed/removed?`);
    return m[0];
}

// Only what is NOT under test. The badge/history/payee decorations render as markers so a diff
// between the 2 modes can only come from the row builder itself.
const stubs = `
function escapeHtml(str) { if (str === null || str === undefined) return ''; return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function escapeAttr(str) { return escapeHtml(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
let currentLang = 'th';
let langData = {};
let lineOverrideHistoryRd = { byKey: {}, historyAvailable: true, startDate: null };
function statusBadgeHtml(enumKey) { return '<!--badge:' + enumKey + '-->'; }
function payeeDescriptorHtmlRd() { return '<div class="payslip-line-tag">PAYEE</div>'; }
function lineOverrideOccurrencesHtml() { return ''; }
function lineOverrideHistoryCellHtml() { return '<!--history-->'; }
function buildFormulaStepsRd() { return null; }
function explainLineNoteRd() { return null; }
function countBadgeHtml() { return '<!--current-->'; }
function lineOverrideHistoryWhoRd() { return 'ผู้ใช้'; }
function formatDisplayDateTime(v) { return String(v); }
function formatDisplayDate(v) { return String(v); }
`;

const extracted = [
    stubs,
    fn(formatSource, 'fmtNum'),
    constDecl(detailSource, 'LINE_OVERRIDE_SKIP_NOTES_RD'),
    fn(detailSource, 'lineOverrideSkipEnumRd'),
    fn(detailSource, 'lineOverrideIsSkippedRd'),
    fn(detailSource, 'lineOverrideHistoryFor'),
    fn(detailSource, 'lineOverrideHistoryValueRd'),
    fn(detailSource, 'lineOverrideMoneyClassRd'),
    fn(detailSource, 'lineOverrideComputedTextRd'),
    fn(detailSource, 'lineOverrideComputedTagHtml'),
    fn(detailSource, 'lineOverrideTagHtmlRd'),
    fn(detailSource, 'formulaTagTextRd'),
    fn(detailSource, 'lineOverrideExemptTextRd'),
    fn(detailSource, 'lineOverrideNoteTextRd'),
    fn(detailSource, 'lineOverrideRowHtml'),
    fn(detailSource, 'lineOverrideTotalsHtmlRd'),
    fn(detailSource, 'lineOverrideHistoryCurrentIndexRd'),
    fn(detailSource, 'lineOverrideHistoryTimelineItemsRd'),
    fn(detailSource, 'lineOverrideHistoryItemHtml'),
    fn(detailSource, 'lineOverrideHistoryWhenRd'),
    fn(detailSource, 'lineOverrideHistoryMetaRd'),
    fn(detailSource, 'lineOverrideHistoryMenuHtml'),
    `module.exports = {
        lineOverrideRowHtml, lineOverrideTotalsHtmlRd, lineOverrideHistoryTimelineItemsRd, lineOverrideHistoryMenuHtml,
        setLang: (d) => { langData = d; },
    };`,
].join('\n');

const Module = require('module');
const m = new Module(detailJsPath);
m._compile(extracted, detailJsPath);
const api = m.exports;
api.setLang(LANG);

let passed = 0;
let failed = 0;
function check(label, cond, extra) {
    if (cond) { passed++; console.log(`  PASS  ${label}`); return; }
    failed++;
    console.log(`  FAIL  ${label}${extra !== undefined ? ' -- ' + extra : ''}`);
}

const GROUPS = {
    base_salary: { type: 'base_salary', key: 'table_base_salary', fallback: 'Base Salary', money: 'money-gross' },
    deduction: { type: 'deduction', key: 'table_deduction_amount', fallback: 'Deductions', money: 'money-deduction' },
    statutory: { type: 'statutory', key: 'line_override_group_statutory', fallback: 'Statutory', money: 'money-deduction' },
};
// One line of every kind the table can hold: a plain one, an overridden one, an excluded one, a
// run-disabled one, and one this employee is not enrolled in.
const LINES = [
    { line: { code: '__base_salary__', name_th: 'เงินเดือนพื้นฐาน', name_en: 'Base Salary', current_amount: 30000, item_type: 'base_salary', line_type: 'earning_deduction' }, group: GROUPS.base_salary, runDisabled: false },
    { line: { code: 'LOAN_REPAY', name_th: 'ผ่อนชำระ', name_en: 'Loan', current_amount: 1500, item_type: 'deduction', line_type: 'earning_deduction', override_action: 'override_amount', override_amount: 1500, computed_amount: 2000, override_note: 'ตกลงแล้ว' }, group: GROUPS.deduction, runDisabled: false },
    { line: { code: 'UNIFORM', name_th: 'ชุดยูนิฟอร์ม', name_en: 'Uniform', current_amount: 0, item_type: 'deduction', line_type: 'earning_deduction', override_action: 'exclude' }, group: GROUPS.deduction, runDisabled: false },
    { line: { code: 'MEAL', name_th: 'ค่าอาหาร', name_en: 'Meal', current_amount: 0, item_type: 'deduction', line_type: 'earning_deduction' }, group: GROUPS.deduction, runDisabled: true },
    { line: { code: 'TH_PVD', name_th: 'กองทุนสำรองเลี้ยงชีพ', name_en: 'PVD', current_amount: 0, item_type: 'statutory', line_type: 'statutory', note: 'employee_not_enrolled' }, group: GROUPS.statutory, runDisabled: false },
];
const render = (mode) => LINES.map((l, i) => api.lineOverrideRowHtml(l.line, i, l.group, l.runDisabled, mode)).join('\n');
const edit = render('edit');
const view = render('view');

const codesOf = (html) => (html.match(/data-item-code="([^"]*)"/g) || []);
console.log('=== (ก) the same rows, in the same order, from the same lines ===');
check('both modes render a row per line', codesOf(edit).length === LINES.length && codesOf(view).length === LINES.length,
    `edit=${codesOf(edit).length} view=${codesOf(view).length}`);
check('...the same codes, in the same order', codesOf(edit).join('|') === codesOf(view).join('|'), codesOf(view).join('|'));

console.log('');
console.log('=== (ข) strip the 2 columns view does not render, and the rest is byte-identical ===');
// The 2 hidden columns, and the one documented difference: a skipped row's reason badge, which the
// editable slip puts in the toggle column it is replacing and the read-only one puts after the name.
const strip = (html) => html
    .replace(/<td class="col-check tbl-sticky-col">[\s\S]*?<\/td>/g, '')
    .replace(/<td class="lo-action-cell">[\s\S]*?<\/td>/g, '')
    .replace(/<!--badge:employee_not_enrolled_pvd-->/g, '')
    .replace(/\s+/g, ' ').trim();
check('every remaining cell of every row matches, character for character', strip(edit) === strip(view),
    strip(edit) === strip(view) ? '' : `\n  edit: ${strip(edit).slice(0, 400)}\n  view: ${strip(view).slice(0, 400)}`);

console.log('');
console.log('=== (ค) the read-only slip has no control in the DOM at all (not hidden -- absent) ===');
['lo-include', 'lo-edit-btn', 'col-check', 'lo-action-cell', 'form-switch'].forEach((needle) => {
    check(`view renders no ${needle}`, view.indexOf(needle) === -1);
    check(`...and edit still does`, edit.indexOf(needle) !== -1);
});
check('nothing is merely hidden: no d-none anywhere in either mode',
    edit.indexOf('d-none') === -1 && view.indexOf('d-none') === -1);
check('the add-a-line row belongs to the editable slip only (it is 4a-2 that puts it in the table)',
    view.indexOf('manual-line-add') === -1);

console.log('');
console.log('=== (ง) the 3 totals: one block, below everything, identical in both modes ===');
const ROW = { gross_amount: 30000, total_deduction_amount: 4500, net_amount: 25500 };
const totals = api.lineOverrideTotalsHtmlRd(ROW);
const figuresOf = (html) => (html.match(/>([\d,]+\.\d\d)</g) || []).join('|');
check('3 rows', (totals.match(/class="lo-total-row/g) || []).length === 3);
check('the 3 figures, in order', figuresOf(totals) === '>30,000.00<|>4,500.00<|>25,500.00<', figuresOf(totals));
check('the 3 labels, from langData', ['payslip_total_earnings', 'payslip_total_deductions', 'table_net_pay']
    .every(k => totals.indexOf(LANG[k]) !== -1));
// One renderer, no mode argument at all -- which is what makes the 2 slips byte-identical here
// rather than merely equal-looking.
check('the block takes no mode: the 2 slips cannot render it differently',
    detailSource.indexOf('function lineOverrideTotalsHtmlRd(row) {') !== -1);
check('nothing is added up on the client -- every figure comes off the run detail row',
    fn(detailSource, 'lineOverrideTotalsHtmlRd').indexOf('+') === -1);
check('a modal opened before its row is known renders no totals rather than zeroes',
    api.lineOverrideTotalsHtmlRd(null) === '');
// Where it sits: after the hand-added block, in BOTH bodies, and nowhere inside the table.
check('the table renders no totals any more', fn(detailSource, 'renderLineOverrideTableRd').indexOf('lineOverrideTotalsHtmlRd') === -1);
['renderBreakdownEditableBodyRd', 'renderBreakdownViewBodyRd'].forEach((name) => {
    const src = fn(detailSource, name);
    check(`${name}() puts the totals block after the hand-added block`,
        src.indexOf('breakdownManualLines') !== -1 && src.indexOf('breakdownNetSummary') > src.indexOf('breakdownManualLines'));
    check(`${name}() fills it from the same row it renders`, src.indexOf('renderBreakdownNetSummaryRd(row);') !== -1);
});

console.log('');
console.log('=== (จ) a skipped row: no switch, no pencil, no figure -- in either mode ===');
const skippedEdit = api.lineOverrideRowHtml(LINES[4].line, 4, GROUPS.statutory, false, 'edit');
const skippedView = api.lineOverrideRowHtml(LINES[4].line, 4, GROUPS.statutory, false, 'view');
check('the editable slip puts the reason where the switch would be, and renders no switch',
    skippedEdit.indexOf('<td class="col-check tbl-sticky-col"><!--badge:employee_not_enrolled_pvd--></td>') !== -1
    && skippedEdit.indexOf('lo-include') === -1, skippedEdit);
check('the read-only slip puts the same badge after the name', skippedView.indexOf('</span> <!--badge:employee_not_enrolled_pvd-->') !== -1, skippedView);
check('neither mode offers a pencil on it', skippedEdit.indexOf('lo-edit-btn') === -1 && skippedView.indexOf('lo-edit-btn') === -1);
check('neither mode prints a figure for it', skippedEdit.indexOf('<div class="lo-amount-view"></div>') !== -1
    && skippedView.indexOf('<div class="lo-amount-view"></div>') !== -1);
check('...and it is not hidden from the table any more', skippedEdit.indexOf('d-none') === -1);

console.log('');
console.log('=== the history modal inherits the slip it was opened from ===');
// Same chain for one line: 2 real edits + the calculated value pinned on top.
const HISTORY = {
    current_value: 1500, original_value: 2000,
    edits: [
        { changed_at: '2026-09-17 10:00:00', old_value: 2000, new_value: 1800, note: null },
        { changed_at: '2026-09-17 11:00:00', old_value: 1800, new_value: 1500, note: 'ตกลงแล้ว' },
    ],
};
const itemsEdit = api.lineOverrideHistoryTimelineItemsRd(HISTORY, true);
const itemsView = api.lineOverrideHistoryTimelineItemsRd(HISTORY, false);
const useBtns = (items) => items.filter(i => (i.actionHtml || '').indexOf('lo-history-use') !== -1).length;
check('the read-only slip renders NO "use this value" button at all', useBtns(itemsView) === 0,
    JSON.stringify(itemsView.map(i => i.actionHtml)));
check('...not even on the pinned "calculated value" row', (itemsView[0].actionHtml || '').indexOf('lo-history-use') === -1,
    itemsView[0].actionHtml);
check('...and none of them is merely disabled or hidden', itemsView.every(i => (i.actionHtml || '').indexOf('disabled') === -1
    && (i.actionHtml || '').indexOf('d-none') === -1));
check('the editable slip renders one per row that is not the current value',
    useBtns(itemsEdit) === itemsEdit.length - 1, `${useBtns(itemsEdit)} of ${itemsEdit.length}`);
check('both slips list the same entries -- only the action differs',
    itemsEdit.length === itemsView.length && itemsEdit.map(i => i.title).join('|') === itemsView.map(i => i.title).join('|'));
check('the "current" badge survives in both', (itemsEdit.concat(itemsView)).filter(i => (i.actionHtml || '').indexOf('<!--current-->') !== -1).length === 2);
// The dropdown behind the badge, same question: in the read-only slip an entry is a fact, not a
// control -- so it is not a button at all, rather than a button that happens to be disabled.
const menuEdit = api.lineOverrideHistoryMenuHtml(HISTORY, false);
const menuView = api.lineOverrideHistoryMenuHtml(HISTORY, true);
const countOf = (html, needle) => (html.split(needle).length - 1);
check('the read-only dropdown has exactly one button: the one that opens the full history',
    countOf(menuView, '<button') === 1 && menuView.indexOf('lo-history-view-all') !== -1,
    String(countOf(menuView, '<button')));
check('...and the editable one has one per entry, plus that same foot',
    countOf(menuEdit, '<button') === HISTORY.edits.length + 1, String(countOf(menuEdit, '<button')));
check('the read-only entries carry no handler hook and no disabled control',
    menuView.indexOf('dropdown-item lo-history-item ') === -1 && menuView.indexOf('disabled') === -1
    && menuView.indexOf('data-value=') === -1, menuView);
check('both dropdowns list the same rows, with the same values and meta',
    countOf(menuView, '<li') === countOf(menuEdit, '<li')
    && countOf(menuView, 'lo-history-value') === countOf(menuEdit, 'lo-history-value')
    && ['1,800.00', '1,500.00', '2,000.00'].every(v => menuView.indexOf(v) !== -1),
    `${countOf(menuView, '<li')} vs ${countOf(menuEdit, '<li')}`);
check('a static entry is ignored by the apply handler, not merely unlikely to be clicked',
    detailSource.indexOf("if ($(this).hasClass('lo-history-item-static')) return;") !== -1);
check('the modal asks the slip, not a second opinion',
    fn(detailSource, 'openLineOverrideHistoryModalRd').indexOf("lineOverrideHostRd.mode !== 'view'") !== -1);

console.log('');
console.log('=== the read-only slip reads the same endpoint, and renders through the same function ===');
const viewBody = fn(detailSource, 'renderBreakdownViewBodyRd');
check('it mounts the same table the editable slip mounts', viewBody.indexOf("setLineOverrideHostRd('#breakdownLineOverrideWrap'") !== -1);
check('...in view mode', viewBody.indexOf("'view'") !== -1);
check('...and loads it through the one loader', viewBody.indexOf('loadSyncLineOverridesRd()') !== -1);
check('there is no second slip renderer left in the file',
    detailSource.indexOf('breakdownViewSlipHtml') === -1 && detailSource.indexOf('function breakdownLineRowsRd') === -1
    && detailSource.indexOf('function statutoryRowsRd') === -1);
check('the table renderer takes the mode as its last parameter, defaulting to the editable one',
    detailSource.indexOf('function renderLineOverrideTableRd(lines, runSettings, mode)') !== -1
    && fn(detailSource, 'renderLineOverrideTableRd').indexOf("mode = mode || 'edit';") !== -1);

console.log('');
console.log('-'.repeat(50));
console.log(`Passed: ${passed}, Failed: ${failed}`);
if (failed > 0) {
    console.log('SOME TESTS FAILED');
    process.exit(1);
}
console.log('ALL TESTS PASSED');
