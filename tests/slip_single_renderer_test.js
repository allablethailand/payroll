/**
 * 2026-09-18, 4a-1 ("สลิปเป็นที่เดียว") -- the read-only slip and the editable slip are ONE renderer.
 *
 * What is locked here is the thing the round exists for: given the same lines, the two modes render
 * the same rows, in the same order, with the same figures -- and 'view' differs ONLY by not
 * rendering the 2 columns it has no use for (never by hiding them, which is how a control nobody
 * may press stays one keyboard tab away).
 *
 * 2026-09-18, 4a-2: hand-added lines are rows of this same table (2 groups of their own), the 3
 * totals are its last rows, and a skipped line is not rendered at all -- so byte-identity between the
 * 2 modes now holds with NO documented exception.
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
function explainLineNoteRd(note) { return note ? '<div>' + note + '</div>' : null; }
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
    fn(detailSource, 'lineOverrideAddLinkHtmlRd'),
    fn(detailSource, 'manualLineToTableRowRd'),
    fn(detailSource, 'lineOverrideTotalsHtmlRd'),
    fn(detailSource, 'lineOverrideHistoryCurrentIndexRd'),
    fn(detailSource, 'lineOverrideHistoryTimelineItemsRd'),
    fn(detailSource, 'lineOverrideHistoryItemHtml'),
    fn(detailSource, 'lineOverrideHistoryWhenRd'),
    fn(detailSource, 'lineOverrideHistoryMetaRd'),
    fn(detailSource, 'lineOverrideHistoryMenuHtml'),
    `module.exports = {
        lineOverrideRowHtml, lineOverrideAddLinkHtmlRd, manualLineToTableRowRd, lineOverrideIsSkippedRd, lineOverrideTotalsHtmlRd, lineOverrideHistoryTimelineItemsRd, lineOverrideHistoryMenuHtml,
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
    manual_earning: { type: 'manual_earning', key: 'line_override_group_manual_earning', fallback: 'Additional earnings', money: 'money-gross', manualType: 'earning' },
    manual_deduction: { type: 'manual_deduction', key: 'line_override_group_manual_deduction', fallback: 'Additional deductions', money: 'money-deduction', manualType: 'deduction' },
};
// 2026-09-18, 4a-2: what manualLineToTableRowRd() hands the row builder. Written out rather than
// piped through that function so this file asserts the ROW, and the mapper is asserted separately
// below -- a bug in the mapper must not be able to hide behind a matching expectation here.
const MANUAL_EARNING = { code: 'BONUS', name_th: 'โบนัส', name_en: 'Bonus', current_amount: 5000, computed_amount: null,
    line_type: 'manual_line', item_type: 'manual_earning', override_action: null, override_amount: null,
    override_note: 'ตามที่ตกลง', note: null, source: null, formula: null, is_exempted: false, exempted_amount: null,
    is_custom: false, is_other: false, payee: null, installment: null, occurrences: null, manual_line_id: 91, ped_type_id: 7 };
const MANUAL_DEDUCTION = Object.assign({}, MANUAL_EARNING, { code: 'FINE', name_th: 'ค่าปรับ', name_en: 'Fine',
    current_amount: 200, item_type: 'manual_deduction', override_note: null, manual_line_id: 92 });
// One line of every kind the table can hold: a plain one, an overridden one, an excluded one, a
// run-disabled one, and one this employee is not enrolled in.
const LINES = [
    { line: { code: '__base_salary__', name_th: 'เงินเดือนพื้นฐาน', name_en: 'Base Salary', current_amount: 30000, item_type: 'base_salary', line_type: 'earning_deduction' }, group: GROUPS.base_salary, runDisabled: false },
    { line: { code: 'LOAN_REPAY', name_th: 'ผ่อนชำระ', name_en: 'Loan', current_amount: 1500, item_type: 'deduction', line_type: 'earning_deduction', override_action: 'override_amount', override_amount: 1500, computed_amount: 2000, override_note: 'ตกลงแล้ว' }, group: GROUPS.deduction, runDisabled: false },
    { line: { code: 'UNIFORM', name_th: 'ชุดยูนิฟอร์ม', name_en: 'Uniform', current_amount: 0, item_type: 'deduction', line_type: 'earning_deduction', override_action: 'exclude' }, group: GROUPS.deduction, runDisabled: false },
    { line: { code: 'MEAL', name_th: 'ค่าอาหาร', name_en: 'Meal', current_amount: 0, item_type: 'deduction', line_type: 'earning_deduction' }, group: GROUPS.deduction, runDisabled: true },
    // Kept as a fixture for the skip predicate below; it is filtered out before the row builder is
    // reached, so RENDER is never asked of it (see section (จ)).
    { line: { code: 'TH_PVD', name_th: 'กองทุนสำรองเลี้ยงชีพ', name_en: 'PVD', current_amount: 0, item_type: 'statutory', line_type: 'statutory', note: 'employee_not_enrolled' }, group: GROUPS.statutory, runDisabled: false, skipped: true },
    { line: { code: 'TH_SSO', name_th: 'ประกันสังคม', name_en: 'SSO', current_amount: 750, item_type: 'statutory', line_type: 'statutory', note: 'no_rate_configured' }, group: GROUPS.statutory, runDisabled: false },
    { line: MANUAL_EARNING, group: GROUPS.manual_earning, runDisabled: false },
    { line: MANUAL_DEDUCTION, group: GROUPS.manual_deduction, runDisabled: false },
];
// What the table really renders: everything the skip gate lets through.
const RENDERED = LINES.filter(l => !l.skipped);
const render = (mode) => RENDERED.map((l, i) => api.lineOverrideRowHtml(l.line, i, l.group, l.runDisabled, mode)).join('\n');
const tableSrc = fn(detailSource, 'renderLineOverrideTableRd');
const edit = render('edit');
const view = render('view');

const codesOf = (html) => (html.match(/data-item-code="([^"]*)"/g) || []);
console.log('=== (ก) the same rows, in the same order, from the same lines ===');
check('both modes render a row per line the gate let through', codesOf(edit).length === RENDERED.length && codesOf(view).length === RENDERED.length,
    `edit=${codesOf(edit).length} view=${codesOf(view).length}`);
check('...the same codes, in the same order', codesOf(edit).join('|') === codesOf(view).join('|'), codesOf(view).join('|'));

console.log('');
console.log('=== (ข) strip the 2 columns view does not render, and the rest is byte-identical ===');
// The 2 hidden columns, and nothing else -- 2026-09-18, 4a-2 follow-up removed the one documented
// exception (a skipped row's reason badge), because a skipped row is no longer rendered at all.
const strip = (html) => html
    .replace(/<td class="col-check tbl-sticky-col">[\s\S]*?<\/td>/g, '')
    .replace(/<td class="lo-action-cell">[\s\S]*?<\/td>/g, '')
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
check('the read-only slip renders neither of the 2 actions a manual row carries',
    view.indexOf('manual-line-edit-btn') === -1 && view.indexOf('manual-line-remove-btn') === -1);
check('...and the editable one renders both', edit.indexOf('manual-line-edit-btn') !== -1
    && edit.indexOf('manual-line-remove-btn') !== -1);

console.log('');
console.log('=== (ง) the 3 totals: the last rows of the table itself, same figures in both modes ===');
const ROW = { gross_amount: 30000, total_deduction_amount: 4500, net_amount: 25500 };
const totalsEdit = api.lineOverrideTotalsHtmlRd(ROW, 'edit');
const totalsView = api.lineOverrideTotalsHtmlRd(ROW, 'view');
const figuresOf = (html) => (html.match(/>([\d,]+\.\d\d)</g) || []).join('|');
check('3 rows in each mode', (totalsEdit.match(/class="lo-total-row/g) || []).length === 3
    && (totalsView.match(/class="lo-total-row/g) || []).length === 3);
check('they are table rows, not a block beside the table',
    totalsEdit.trim().indexOf('<tr class="lo-total-row') === 0 && totalsEdit.indexOf('<div') === -1);
check('the 3 figures, in order, identical in both modes',
    figuresOf(totalsEdit) === '>30,000.00<|>4,500.00<|>25,500.00<' && figuresOf(totalsView) === figuresOf(totalsEdit),
    figuresOf(totalsEdit));
check('the 3 labels, from langData', ['payslip_total_earnings', 'payslip_total_deductions', 'table_net_pay']
    .every(k => totalsEdit.indexOf(LANG[k]) !== -1));
// The only thing `mode` may decide: how many cells the label and the empty tail span, because the
// read-only slip renders 2 columns fewer. Every figure and every label is the same in both.
check('mode only changes the colspans', totalsEdit.replace(/colspan="\d"/g, 'colspan') === totalsView.replace(/colspan="\d"/g, 'colspan'));
check('every totals row is 2 cells: a label and a figure', (totalsEdit.match(/<td/g) || []).length === 6
    && (totalsView.match(/<td/g) || []).length === 6, totalsEdit);
// The figure is in the LAST cell of the row and spans every column after the label, so it ends on
// the table's own right edge rather than stopping at the money column with empty cells behind it.
check('the figure sits in the last cell, spanning to the right edge (edit: 2 + 3 of 5)',
    totalsEdit.indexOf('colspan="2"') !== -1 && totalsEdit.indexOf('lo-total-amount" colspan="3"') !== -1, totalsEdit);
check('...and in the read-only slip too (1 + 2 of 3)',
    totalsView.indexOf('colspan="1"') !== -1 && totalsView.indexOf('lo-total-amount" colspan="2"') !== -1, totalsView);
check('nothing follows the figure: it IS the row\'s last cell',
    /lo-total-amount[\s\S]*?<\/td>\s*<\/tr>/.test(totalsEdit) && /lo-total-amount[\s\S]*?<\/td>\s*<\/tr>/.test(totalsView));
check('nothing is added up on the client -- every figure comes off the run detail row',
    fn(detailSource, 'lineOverrideTotalsHtmlRd').indexOf('+') === -1);
check('a modal opened before its row is known renders no totals rather than zeroes',
    api.lineOverrideTotalsHtmlRd(null, 'edit') === '');
// Where they sit: the LAST thing the table's own body builder appends, in both modes, and in no
// block outside it -- 4a-1's `.lo-totals-block` under the table is gone with the card it sat under.
check('the table body ends with them', tableSrc.indexOf('body += lineOverrideTotalsHtmlRd(breakdownRowRd, mode);') !== -1);
check('...and they are appended after every group, not inside one',
    tableSrc.indexOf('body += lineOverrideTotalsHtmlRd') > tableSrc.indexOf('LINE_OVERRIDE_GROUPS_RD.forEach'));
['renderBreakdownEditableBodyRd', 'renderBreakdownViewBodyRd'].forEach((name) => {
    const src = fn(detailSource, name);
    check(`${name}() mounts the table and nothing else`,
        src.indexOf('breakdownManualLines') === -1 && src.indexOf('breakdownNetSummary') === -1
        && src.indexOf('lo-totals-block') === -1 && src.indexOf('ml-mount') === -1, src);
});
check('no block, class or renderer of the retired card survives anywhere in the file',
    ['ml-mount', 'lo-totals-block', 'breakdownNetSummary', 'breakdownManualLines', 'payslipViewHtml',
     'manualLineListItemHtml', 'renderBreakdownNetSummaryRd', 'manualLineAddButtonHtml']
        .every(needle => detailSource.indexOf(needle) === -1));

console.log('=== (จ) a skipped line is not a row of either slip ===');
// 2026-09-18, 4a-2 follow-up: the row builder never sees one -- the TABLE builder drops it, so what
// is asserted here is the gate itself plus the predicate it calls.
check('the table builder drops a skipped line before it can become a row',
    tableSrc.indexOf('&& !lineOverrideIsSkippedRd(l));') !== -1, tableSrc);
// It is part of `groupLines`, which BOTH modes compute -- no `isView` anywhere in that statement.
check('...in both modes -- the gate is not behind an `isView` branch', (function () {
    const i = tableSrc.indexOf('const groupLines =');
    const j = tableSrc.indexOf(';', tableSrc.indexOf('!lineOverrideIsSkippedRd(l)'));
    return i !== -1 && j > i && tableSrc.slice(i, j).indexOf('isView') === -1;
})(), tableSrc.slice(tableSrc.indexOf('const groupLines ='), tableSrc.indexOf('const groupLines =') + 200));
check('the row builder carries no skipped branch left over',
    ['skipBadge', 'lo-row-skipped', 'payroll_statutory_skip', 'lineOverrideSkipEnumRd(line)']
        .every(needle => fn(detailSource, 'lineOverrideRowHtml').indexOf(needle) === -1));
check('the reason badge has no caller left anywhere in the file',
    detailSource.indexOf('payroll_statutory_skip') === -1);
// The predicate itself is unchanged: a row carrying a personal override still gets through, or that
// override would be unreachable.
check('a not-enrolled line is skipped', api.lineOverrideIsSkippedRd(LINES[4].line) === true);
check('...but not once somebody has overridden it',
    api.lineOverrideIsSkippedRd(Object.assign({}, LINES[4].line, { override_action: 'override_amount' })) === false);
check('an ordinary statutory line is never skipped', api.lineOverrideIsSkippedRd(LINES[5].line) === false);
check('neither is a hand-added one', api.lineOverrideIsSkippedRd(MANUAL_EARNING) === false);

// The cut is scoped to skipped rows: an ordinary statutory row still says what it is AND shows its sum.
const statutoryNormal = api.lineOverrideRowHtml(LINES[5].line, 5, GROUPS.statutory, false, 'edit');
check('an ordinary statutory row keeps its "statutory" badge', statutoryNormal.indexOf('<!--badge:statutory-->') !== -1);
check('...and keeps its formula tag', statutoryNormal.indexOf('no_rate_configured') !== -1, statutoryNormal);

console.log('');
console.log('=== a hand-added line is a ROW of this table (2026-09-18, 4a-2) ===');
const manualEdit = api.lineOverrideRowHtml(MANUAL_EARNING, 5, GROUPS.manual_earning, false, 'edit');
const manualView = api.lineOverrideRowHtml(MANUAL_EARNING, 5, GROUPS.manual_earning, false, 'view');
check('it is a row of the table, marked as the hand-added kind', manualEdit.indexOf('<tr class="lo-row lo-row-manual"') === 0);
check('its toggle cell is present but empty -- nothing calculated it, so there is nothing to put back',
    manualEdit.indexOf('<td class="col-check tbl-sticky-col"></td>') !== -1 && manualEdit.indexOf('lo-include') === -1);
check('it carries the 2 actions, addressed by the line id, not by item code',
    manualEdit.indexOf('manual-line-edit-btn" data-line-id="91"') !== -1
    && manualEdit.indexOf('manual-line-remove-btn" data-line-id="91"') !== -1, manualEdit);
check('...and never the override pencil, which would write against a code 2 lines may share',
    manualEdit.indexOf('lo-edit-btn') === -1);
check('its history cell is empty: an override history is not a thing it can have',
    manualEdit.indexOf('<td class="lo-history-cell"></td>') !== -1);
check('the read-only slip renders the same row with neither action column nor buttons',
    manualView.indexOf('lo-action-cell') === -1 && manualView.indexOf('manual-line-remove-btn') === -1);
check('its figure takes the money colour of the group it is in',
    manualEdit.indexOf('money-gross') !== -1
    && api.lineOverrideRowHtml(MANUAL_DEDUCTION, 6, GROUPS.manual_deduction, false, 'edit').indexOf('money-deduction') !== -1);
// The note becomes the row's own tag, under the payee line -- the order the retired card showed them
// in -- and never a "system calculated x" tag, which would claim a value it never had.
check('its note renders as the row tag, after the payee line',
    manualEdit.indexOf(LANG['note'] + ': ') !== -1, manualEdit);
check('...and no computed-value tag, because nothing was overridden',
    manualEdit.indexOf(LANG['line_override_computed_inline'].split('{')[0]) === -1, manualEdit);

console.log('');
console.log('=== the mapper: renamed fields, nothing computed ===');
const mapped = api.manualLineToTableRowRd({ id: 91, ped_type_id: 7, amount: 5000, note: 'agreed', item_code: 'BONUS',
    item_name_th: 'BONUS_TH', item_name_en: 'Bonus', item_type: 'earning', is_custom: false, is_other: false,
    payee: null, installment: null });
check('the amount is carried over untouched', mapped.current_amount === 5000);
check('the item code and both names land where the row builder reads them',
    mapped.code === 'BONUS' && mapped.name_th === 'BONUS_TH' && mapped.name_en === 'Bonus');
check('the type routes it into the earning group', mapped.item_type === 'manual_earning');
check('...and a deduction into the other one',
    api.manualLineToTableRowRd({ item_type: 'deduction' }).item_type === 'manual_deduction');
check('it is marked as a manual line, which is what turns off the switch and the history',
    mapped.line_type === 'manual_line');
check('the note becomes the tag the row prints', mapped.override_note === 'agreed' && mapped.note === null);
check('override_action stays unset -- a hand-added line replaced no calculated value',
    mapped.override_action === null && mapped.override_amount === null && mapped.computed_amount === null);
check('the 2 ids the buttons need survive', mapped.manual_line_id === 91 && mapped.ped_type_id === 7);
check('nothing is computed in there', fn(detailSource, 'manualLineToTableRowRd').indexOf('+') === -1);

console.log('');
console.log('=== the "add a line" link, on the group head ===');
const addLink = api.lineOverrideAddLinkHtmlRd(GROUPS.manual_earning);
// 2026-09-18, 4a-2 follow-up 3: it is no longer a row of its own -- a row that repeated what the
// heading above it already said. It rides on that heading, which is the group it acts on.
check('it is a link, not a row', addLink.indexOf('<tr') === -1 && addLink.indexOf('<td') === -1
    && addLink.trim().indexOf('<button') === 0, addLink);
check('a worded text button, not a solid one', addLink.indexOf('btn btn-link lo-add-line-btn') !== -1
    && addLink.indexOf('btn-primary') === -1 && addLink.indexOf('<i ') === -1);
check('it carries the same word the form submits with', addLink.indexOf(LANG['add_line']) !== -1);
check('...and the group it was pressed in decides the new line type',
    addLink.indexOf('data-item-type="earning"') !== -1
    && api.lineOverrideAddLinkHtmlRd(GROUPS.manual_deduction).indexOf('data-item-type="deduction"') !== -1);
check('the group head is still ONE row with one full-width cell',
    tableSrc.indexOf('<tr class="lo-group"><td colspan="${colCount}">') !== -1
    && tableSrc.indexOf('lo-row-add') === -1, tableSrc);
check('...and the flex is inside the cell, never on the `<td>` itself',
    tableSrc.indexOf('<div class="lo-group-head">') !== -1);
check('the heading keeps its sticky span, so 430 is unchanged',
    tableSrc.indexOf('<span class="lo-span-sticky">') !== -1);
check('only a manual group gets the link, and only in the editable slip',
    tableSrc.indexOf('${manualOpen ? lineOverrideAddLinkHtmlRd(group) : \'\'}') !== -1
    && tableSrc.indexOf('const manualOpen = !!group.manualType && !isView;') !== -1, tableSrc);
check('there is no add ROW left to render anywhere',
    detailSource.indexOf('lineOverrideAddRowHtmlRd') === -1 && detailSource.indexOf('lo-row-add') === -1);
check('an EMPTY manual group still renders in the editable slip -- its head is the way in',
    tableSrc.indexOf('if (!groupLines.length && !manualOpen) return;') !== -1);
check('the 2 manual groups sit after statutory and before "other"', (function () {
    const src = detailSource.slice(detailSource.indexOf('const LINE_OVERRIDE_GROUPS_RD'));
    const order = ['statutory', 'manual_earning', 'manual_deduction', 'other'].map(t => src.indexOf("type: '" + t + "'"));
    return order.every((v, i) => v !== -1 && (i === 0 || v > order[i - 1]));
})());

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
