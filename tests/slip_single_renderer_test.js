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
function countBadgeHtml() { return '<!--count-->'; }
function formatDisplayDateTime(v) { return String(v); }
function formatDisplayDate(v) { return String(v); }
`;

const extracted = [
    stubs,
    fn(formatSource, 'fmtNum'),
    constDecl(detailSource, 'LINE_OVERRIDE_SKIP_NOTES_RD'),
    fn(detailSource, 'lineOverrideSkipEnumRd'),
    fn(detailSource, 'lineOverrideIsSkippedRd'),
    fn(detailSource, 'lineOverrideIsChangedRd'),
    "let lineOverrideViewFilterRd = 'all';",
    fn(detailSource, 'lineOverrideTabsHtmlRd'),
    fn(detailSource, 'lineOverrideHistoryKeyRd'),
    fn(detailSource, 'lineOverrideHistoryFor'),
    fn(detailSource, 'lineOverrideHistoryValueRd'),
    fn(detailSource, 'lineOverrideMoneyClassRd'),
    fn(detailSource, 'lineOverrideComputedTextRd'),
    fn(detailSource, 'lineOverrideComputedTagHtml'),
    fn(detailSource, 'lineOverrideTagHtmlRd'),
    fn(detailSource, 'formulaTagTextRd'),
    fn(detailSource, 'lineOverrideExemptTextRd'),
    fn(detailSource, 'lineOverrideNoteTextRd'),
    // 2026-09-18, 4b: the tri-state the TH_PIT/TH_SSO rows carry -- real, not stubbed, so what the
    // row builder does with it here is what it does in the page.
    constDecl(detailSource, 'STATUTORY_EXEMPTION_FIELD_RD'),
    'let lineOverrideExemptionRd = null;',
    fn(detailSource, 'statutoryExemptionFieldRd'),
    fn(detailSource, 'statutoryExemptionStateRd'),
    fn(detailSource, 'statutoryExemptionInheritRd'),
    fn(detailSource, 'statutoryExemptionEffectiveRd'),
    fn(detailSource, 'statutoryExemptionChangedRd'),
    fn(detailSource, 'statutoryExemptionTagHtmlRd'),
    fn(detailSource, 'lineOverrideRowHtml'),
    fn(detailSource, 'lineOverrideAddLinkHtmlRd'),
    fn(detailSource, 'manualLineToTableRowRd'),
    fn(detailSource, 'lineOverrideTotalsHtmlRd'),
    fn(detailSource, 'lineOverrideChangeTagTextRd'),
    fn(detailSource, 'lineOverrideHistoryRowKindRd'),
    fn(detailSource, 'lineOverrideHistoryTextRd'),
    fn(detailSource, 'lineOverrideHistorySideRd'),
    fn(detailSource, 'lineOverrideHistoryComputedRowsRd'),
    fn(detailSource, 'lineOverrideHistorySameValueRd'),
    fn(detailSource, 'lineOverrideHistoryUseCellHtml'),
    fn(detailSource, 'lineOverrideHistoryTitlebarHtmlRd'),
    fn(detailSource, 'lineOverrideHistoryTableHtmlRd'),
    `module.exports = {
        setExemption: (e) => { lineOverrideExemptionRd = e; },
        lineOverrideRowHtml, lineOverrideAddLinkHtmlRd, manualLineToTableRowRd, lineOverrideIsSkippedRd, lineOverrideTotalsHtmlRd,
        lineOverrideHistoryTableHtmlRd, lineOverrideHistoryTitlebarHtmlRd, lineOverrideHistoryFor, lineOverrideChangeTagTextRd, lineOverrideHistorySameValueRd,
        lineOverrideIsChangedRd, lineOverrideTabsHtmlRd,
        setLang: (d) => { langData = d; },
        setHistory: (h) => { lineOverrideHistoryRd = h; },
        setFilter: (f) => { lineOverrideViewFilterRd = f; },
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
// The 2 hidden columns, plus ONE documented exception (2026-09-19, H-ui): the read-only slip marks
// the lines somebody touched with a tag, because it is the only slip that has nothing else saying
// so -- the editable one already carries the switch, the pencil and the "System: x" sub-line on
// those same rows, and a 4th way of saying it there would be noise (rules.md 0.3). It is a TAG, not
// a column, so the rule this assertion guards -- the 2 modes differ by columns -- still holds.
const strip = (html) => html
    .replace(/<td class="col-check tbl-sticky-col">[\s\S]*?<\/td>/g, '')
    .replace(/<td class="lo-action-cell">[\s\S]*?<\/td>/g, '')
    .replace(new RegExp('<div class="payslip-line-tag">(' + LANG['line_override_row_tag_edited']
        + '|' + LANG['line_override_row_tag_added'] + ')</div>', 'g'), '')
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
console.log('=== (ง) the 3 totals: a block under the table, outside its scroller, same in both modes ===');
const ROW = { gross_amount: 30000, total_deduction_amount: 4500, net_amount: 25500 };
const totalsEdit = api.lineOverrideTotalsHtmlRd(ROW, 'edit');
const totalsView = api.lineOverrideTotalsHtmlRd(ROW, 'view');
const figuresOf = (html) => (html.match(/>([\d,]+\.\d\d)</g) || []).join('|');
check('3 rows in each mode', (totalsEdit.match(/class="lo-totals-row/g) || []).length === 3
    && (totalsView.match(/class="lo-totals-row/g) || []).length === 3);
// 2026-09-19, tiny-4b-fix1 v2: NOT rows of the table. Below `sm` that table is a horizontal scroller
// 136px wider than its host, so a row of it -- whatever cells it is built from -- carries its figures
// out of view with every drag, and the colspan version pinned to nothing at all.
check('they are a block of their own, with no table markup in them',
    totalsEdit.trim().indexOf('<div class="lo-totals">') === 0
    && totalsEdit.indexOf('<tr') === -1 && totalsEdit.indexOf('<td') === -1, totalsEdit);
check('the 3 figures, in order, identical in both modes',
    figuresOf(totalsEdit) === '>30,000.00<|>4,500.00<|>25,500.00<' && figuresOf(totalsView) === figuresOf(totalsEdit),
    figuresOf(totalsEdit));
check('the 3 labels, from langData', ['payslip_total_earnings', 'payslip_total_deductions', 'table_net_pay']
    .every(k => totalsEdit.indexOf(LANG[k]) !== -1));
// Nothing here depends on how many columns the table has, which is the whole point of the move: the
// 2 modes now render byte-identical totals.
check('both modes render exactly the same block', totalsEdit === totalsView, totalsEdit);
check('every row is 2 spans: a label and a figure', (totalsEdit.match(/<span/g) || []).length === 6
    && (totalsView.match(/<span/g) || []).length === 6, totalsEdit);
check('the label comes first and carries no money tone of its own',
    (totalsEdit.match(/<span class="lo-totals-label">/g) || []).length === 3
    && /<span class="lo-totals-label">[^<]+<\/span>/.test(totalsEdit), totalsEdit);
check('the figure keeps the 3 money tones the rows it replaced had',
    totalsEdit.indexOf('num money-gross') !== -1 && totalsEdit.indexOf('num money-deduction') !== -1
    && totalsEdit.indexOf('num money-net') !== -1, totalsEdit);
check('nothing follows the figure inside a row',
    (totalsEdit.match(/<span class="num [^"]+">[^<]*<\/span>\s*<\/div>/g) || []).length === 3, totalsEdit);
check('only the net row is marked, and it is the last one',
    (totalsEdit.match(/lo-totals-row-net/g) || []).length === 1
    && totalsEdit.lastIndexOf('lo-totals-row-net') > totalsEdit.indexOf('money-deduction'), totalsEdit);
check('nothing is added up on the client -- every figure comes off the run detail row',
    (() => {
        const src = fn(detailSource, 'lineOverrideTotalsHtmlRd');
        // The only `+` left in this builder joins strings; none of it touches a figure.
        return !/amount\s*\+|\+\s*row\./.test(src)
            && ['row.gross_amount', 'row.total_deduction_amount', 'row.net_amount'].every(f => src.indexOf(f) !== -1);
    })(), fn(detailSource, 'lineOverrideTotalsHtmlRd'));
check('a modal opened before its row is known renders no totals rather than zeroes',
    api.lineOverrideTotalsHtmlRd(null, 'edit') === '');
// Where they sit: appended to the host AFTER the scroller closes, so they are never inside an element
// that scrolls sideways -- and never in the table body, which is what put them inside it.
check('the block is appended after the scroller closes',
    tableSrc.indexOf('</table></div>` + lineOverrideTotalsHtmlRd(breakdownRowRd)') !== -1
    && tableSrc.indexOf('body += lineOverrideTotalsHtmlRd') === -1, tableSrc);
check('...and it is the last thing the host is given',
    tableSrc.indexOf('lineOverrideTotalsHtmlRd(breakdownRowRd)') > tableSrc.indexOf('LINE_OVERRIDE_GROUPS_RD.forEach'));
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
    tableSrc.indexOf('&& !lineOverrideIsSkippedRd(l, mode)') !== -1, tableSrc);
// 2026-09-18, 4b: `mode` is passed THROUGH to the predicate (the 2 tri-state rows answer it
// differently per slip -- see lineOverrideIsSkippedRd()); the gate itself is still one statement both
// modes run.
// It is part of `groupLines`, which BOTH modes compute -- no `isView` anywhere in that statement.
// (2026-09-18, 4a-2b: measured from `const groupLines =`, not from the first mention of the
// predicate in the file -- the tab count above it calls the same one.)
check('...in both modes -- the gate is not behind an `isView` branch', (function () {
    const i = tableSrc.indexOf('const groupLines =');
    const j = tableSrc.indexOf(';', i);
    return i !== -1 && j > i && tableSrc.slice(i, j).indexOf('isView') === -1
        && tableSrc.slice(i, j).indexOf('!lineOverrideIsSkippedRd(l, mode)') !== -1;
})(), tableSrc.slice(tableSrc.indexOf('const groupLines ='), tableSrc.indexOf('const groupLines =') + 260));
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
// 2026-09-19, H-ui: it has a history cell like every other row -- a hand-added line HAS had an
// amount trail since H-backend, and the cell decides for itself whether there is a badge to draw.
check('its history cell goes through the same builder every other row uses',
    manualEdit.indexOf('<td class="lo-history-cell"><!--history--></td>') !== -1, manualEdit);
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
console.log('=== the history table inherits the slip it was opened from (2026-09-19, H-ui) ===');
// One line, 2 real edits, newest first -- exactly the order api/payroll-run.line-history returns.
const HIST_LINE = { code: 'LOAN', line_type: 'earning_deduction', current_amount: 1500,
    override_action: 'override_amount', computed_amount: 2000 };
const histRow = (over) => Object.assign({ source_type: 'override', line_type: 'earning_deduction',
    item_code: 'LOAN', source_id: null, old_text: null, new_text: null, note: null,
    changed_by_name_th: 'ผู้ใช้', changed_by_name_en: 'User' }, over);
const HIST_ROWS = [
    histRow({ changed_at: '2026-09-17 11:00:00', old_value: 1800, new_value: 1500, note: 'ตกลงแล้ว' }),
    histRow({ changed_at: '2026-09-17 10:00:00', old_value: 2000, new_value: 1800 }),
];
const countOf = (html, needle) => (html.split(needle).length - 1);
const tblEdit = api.lineOverrideHistoryTableHtmlRd(HIST_LINE, HIST_ROWS, 'edit');
const tblView = api.lineOverrideHistoryTableHtmlRd(HIST_LINE, HIST_ROWS, 'view');
check('one table, not a second surface stacked on the slip', countOf(tblEdit, '<table') === 1
    && countOf(tblView, '<table') === 1);
check('the editable slip has 4 column heads, the read-only one 3',
    countOf(tblEdit, '<th class') === 4 && countOf(tblView, '<th class') === 3,
    `${countOf(tblEdit, '<th class')}/${countOf(tblView, '<th class')}`);
check('the read-only slip renders NO "use this value" at all -- not a disabled one, not a hidden one',
    tblView.indexOf('lo-history-use') === -1 && tblView.indexOf('disabled') === -1
    && tblView.indexOf('d-none') === -1, tblView);
check('both slips list the same entries -- only the action column differs',
    countOf(tblView, '<tr') === countOf(tblEdit, '<tr')
    && ['1,800.00', '1,500.00', '2,000.00'].every(v => tblView.indexOf(v) !== -1), tblView);
check('every entry is listed -- the list is never cut to the first N',
    countOf(api.lineOverrideHistoryTableHtmlRd(HIST_LINE,
        Array.from({ length: 38 }, (_, i) => histRow({ changed_at: '2026-09-1' + (i % 9) + ' 08:00:00', old_value: i, new_value: i + 1 })),
        'view'), '<tr') === 38 + 1);
check('newest first, as the endpoint hands them over',
    tblEdit.indexOf('11:00:00') < tblEdit.indexOf('10:00:00'));
// 2026-09-19: the baseline is not an edit and has no time of its own, so it is not a row of the
// list at all -- as the list's first row it also scrolled away, which is what a baseline must not do.
check('the calculated value is in the title bar, above the list',
    tblEdit.indexOf('lo-history-computed-line') !== -1
    && tblEdit.indexOf('lo-history-computed-line') < tblEdit.indexOf('<table'), tblEdit);
check('...and no longer a row of the list', countOf(tblEdit, 'lo-history-computed-row') === 0);
check('...so the first row of the list is a real edit', tblEdit.indexOf('11:00:00') < tblEdit.indexOf('10:00:00')
    && tblEdit.indexOf('<tbody>') < tblEdit.indexOf('11:00:00'), tblEdit);
check('one list, one title bar, no second surface',
    countOf(tblEdit, '<table') === 1 && countOf(tblEdit, 'lo-history-titlebar') === 1);
check('one button per entry that is not the value in force',
    countOf(tblEdit, 'lo-history-use"') === 2, String(countOf(tblEdit, 'lo-history-use"')));
check('...and the one in force carries the word instead, exactly once',
    countOf(tblEdit, 'lo-history-current') === 1
    && tblEdit.indexOf(LANG['line_override_history_current']) !== -1, tblEdit);
check('the button is the small NEUTRAL one -- never this view\'s primary, 38 times over',
    tblEdit.indexOf('btn btn-sm btn-outline-secondary lo-history-use') !== -1
    && tblEdit.indexOf('btn-outline-primary') === -1 && tblEdit.indexOf('btn-primary') === -1);
// 2026-09-19, reported for real (EM009 / LOAN_REPAY): an OLDER entry that repeats the live figure
// was pressable, and what it sent was an x -> x the server accepted and recorded nothing for. The
// word "Current" stays on the ONE live entry; the repeats stay buttons, but disabled ones.
const REPEAT_ROWS = [
    histRow({ changed_at: '2026-09-17 18:08:00', old_value: 1500, new_value: 4000 }),
    histRow({ changed_at: '2026-09-17 12:06:00', old_value: 4000, new_value: 4000 }),
    histRow({ changed_at: '2026-09-17 10:00:00', old_value: 2000, new_value: 1500 }),
];
const tblRepeat = api.lineOverrideHistoryTableHtmlRd(
    Object.assign({}, HIST_LINE, { current_amount: 4000 }), REPEAT_ROWS, 'edit');
check('only one entry is called the current one, however many repeat its figure',
    countOf(tblRepeat, 'lo-history-current') === 1, tblRepeat);
check('...and every repeat of it is a button that cannot be pressed',
    countOf(tblRepeat, 'data-label="4,000.00" disabled') === 1, tblRepeat);
check('...while an entry holding a different figure stays pressable',
    countOf(tblRepeat, 'data-label="1,500.00">') === 1, tblRepeat);
// Compared on the raw value with a tolerance, not on the formatted string.
check('a figure that differs only past the 3rd decimal is the same figure',
    api.lineOverrideHistorySameValueRd('amount', 4000.0001, { current_amount: 4000 }, null) === true
    && api.lineOverrideHistorySameValueRd('amount', 4000.01, { current_amount: 4000 }, null) === false);
check('a masked figure is never claimed to be equal to anything',
    api.lineOverrideHistorySameValueRd('amount', 'XXXX', { current_amount: 4000 }, null) === false);
// The way out sits in the LAST head cell there is -- which differs by mode, because the editable
// slip has a 4th column and the read-only one does not.
check('both slips carry the way out exactly once, with no new copy for it',
    countOf(tblEdit, 'lo-history-close') === 1 && countOf(tblView, 'lo-history-close') === 1
    && tblEdit.indexOf(LANG['close']) !== -1 && tblView.indexOf(LANG['close']) !== -1);
check('...and the editable slip own column title is for screen readers, not repeated text',
    tblEdit.indexOf('<span class="visually-hidden">' + LANG['line_override_history_use_value'] + '</span>') !== -1, tblEdit);
check('a note is a second line under the change, not a column of its own',
    tblEdit.indexOf('<div class="lo-history-note">ตกลงแล้ว</div>') !== -1 && countOf(tblEdit, '<th class') === 4);
check('the calculated row means "drop the override", which is what data-computed marks',
    countOf(tblEdit, 'data-computed="1"') === 1 && tblEdit.indexOf('data-kind="amount"') !== -1);
// The title bar is built on its own and can be asked directly.
const barEdit = api.lineOverrideHistoryTitlebarHtmlRd(HIST_LINE, HIST_ROWS, false);
const barView = api.lineOverrideHistoryTitlebarHtmlRd(HIST_LINE, HIST_ROWS, true);
check('the title bar states the calculated figure, and offers it only where it can be used',
    barEdit.indexOf('2,000.00') !== -1 && barView.indexOf('2,000.00') !== -1
    && countOf(barEdit, 'lo-history-use') === 1 && countOf(barView, 'lo-history-use') === 0,
    barView);
check('both slips carry the way out, as the 32px neutral circle',
    countOf(barEdit, 'btn btn-icon lo-history-close') === 1
    && countOf(barView, 'btn btn-icon lo-history-close') === 1
    && barEdit.indexOf('fa-xmark') !== -1);
check('a hand-added line has no baseline at all, and says nothing rather than something untrue',
    api.lineOverrideHistoryTitlebarHtmlRd(MANUAL_EARNING, [], false)
        .indexOf('lo-history-computed-line') === -1);
// Nothing has replaced the calculated figure, so the pinned row IS the value in force -- and no
// entry below it may claim to be as well.
check('an untouched line marks the calculated row, and only it, as the value in force',
    countOf(api.lineOverrideHistoryTableHtmlRd(Object.assign({}, HIST_LINE, { override_action: null, current_amount: 2000 }),
        HIST_ROWS, 'edit'), 'lo-history-current') === 1);

console.log('');
console.log('=== the 2 tri-state rows record a WORD, and can carry both trails at once ===');
api.setExemption({ tax_calculate_override: 'no', tax_inherit_effective: 'yes',
    sso_calculate_override: 'inherit', sso_inherit_effective: 'yes' });
const PIT_LINE = { code: 'TH_PIT', line_type: 'statutory', current_amount: 0, override_action: null };
const tblPit = api.lineOverrideHistoryTableHtmlRd(PIT_LINE, [histRow({ source_type: 'exemption',
    line_type: 'statutory', item_code: 'TH_PIT', changed_at: '2026-09-18 09:00:00',
    old_value: null, new_value: null, old_text: 'inherit', new_text: 'no' })], 'edit');
check('the entry prints the words the rest of the slip says it with, never a figure',
    tblPit.indexOf(LANG['calc_override_no']) !== -1 && tblPit.indexOf(LANG['calc_override_inherit']) !== -1
    && tblPit.indexOf('0.00') === -1, tblPit);
check('its calculated row is what inherit resolves to, and applies that third value',
    tblPit.indexOf('data-value="inherit"') !== -1 && tblPit.indexOf('data-kind="text"') !== -1, tblPit);
check('...and no amount row is invented for a line with no amount trail',
    countOf(tblPit, 'lo-history-computed-line') === 1, tblPit);
api.setExemption(null);

console.log('');
console.log('=== a hand-added line has a trail, but never a calculated value ===');
const tblManual = api.lineOverrideHistoryTableHtmlRd(MANUAL_EARNING, [histRow({ source_type: 'manual_line',
    item_code: 'BONUS', source_id: 91, changed_at: '2026-09-18 10:00:00', old_value: 4000, new_value: 5000 })], 'edit');
check('no calculated row: nothing calculated it, and a figure there would be one that never existed',
    tblManual.indexOf('lo-history-computed-row') === -1, tblManual);
check('...but its own amount trail is listed, and the live figure is marked',
    tblManual.indexOf('5,000.00') !== -1 && tblManual.indexOf('4,000.00') !== -1
    && countOf(tblManual, 'lo-history-current') === 1, tblManual);

console.log('');
console.log('=== which recorded rows belong to which line ===');
api.setHistory({ historyAvailable: true, startDate: null, byKey: { 'earning_deduction|BONUS': [
    { source_type: 'manual_line', source_id: 91, old_value: 1, new_value: 2 },
    { source_type: 'manual_line', source_id: 92, old_value: 3, new_value: 4 },
    { source_type: 'override', source_id: null, old_value: 5, new_value: 6 },
] } });
check('a hand-added row sees only its OWN entries, by PK -- 2 of them can share one item_code',
    api.lineOverrideHistoryFor(MANUAL_EARNING).length === 1
    && api.lineOverrideHistoryFor(MANUAL_EARNING)[0].source_id === 91,
    JSON.stringify(api.lineOverrideHistoryFor(MANUAL_EARNING)));
check('...and a calculated row under the same code never sees a hand-added entry',
    api.lineOverrideHistoryFor({ code: 'BONUS', line_type: 'earning_deduction' }).length === 1);
api.setHistory({ byKey: {}, historyAvailable: true, startDate: null });

console.log('');
console.log('=== the read-only slip marks the lines somebody touched (2026-09-19, H-ui) ===');
check('a hand-added line and an edited one carry DIFFERENT words: they are different facts',
    api.lineOverrideChangeTagTextRd(MANUAL_EARNING) === LANG['line_override_row_tag_added']
    && api.lineOverrideChangeTagTextRd({ override_action: 'override_amount' }) === LANG['line_override_row_tag_edited']);
check('...an excluded line counts as edited, because excluding IS an edit',
    api.lineOverrideChangeTagTextRd({ override_action: 'exclude' }) === LANG['line_override_row_tag_edited']);
check('an untouched line carries nothing at all -- no tag, no dash',
    api.lineOverrideChangeTagTextRd({ code: 'X' }) === '');
const taggedView = api.lineOverrideRowHtml(MANUAL_EARNING, 5, GROUPS.manual_earning, false, 'view');
check('the tag is the quiet shared one, in the read-only slip only',
    taggedView.indexOf('<div class="payslip-line-tag">' + LANG['line_override_row_tag_added'] + '</div>') !== -1
    && manualEdit.indexOf(LANG['line_override_row_tag_added']) === -1, taggedView);
check('...and it is the LAST tag of the row, after the note',
    taggedView.lastIndexOf(LANG['line_override_row_tag_added']) > taggedView.indexOf(LANG['note'] + ': '), taggedView);

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
console.log('=== (ฉ) the read-only slip: 2 tabs: a filter over rows that are already here (4a-2b) ===');
// The predicate both the count and the filter use -- one definition, so the number on the tab and
// the rows behind it cannot disagree.
check('an overridden row counts as changed', api.lineOverrideIsChangedRd({ override_action: 'override_amount' }) === true);
check('...an excluded one too -- excluding IS a change', api.lineOverrideIsChangedRd({ override_action: 'exclude' }) === true);
check('...and a hand-added one, which replaced nothing but is not calculated either',
    api.lineOverrideIsChangedRd(MANUAL_EARNING) === true);
check('an untouched calculated row does not', api.lineOverrideIsChangedRd(LINES[5].line) === false);
// A skipped row is not a row of this slip at all, so it cannot be counted as a changed one -- but a
// skipped row somebody HAS overridden renders, and therefore counts.
const countOfRows = (rows) => rows.filter(l => !api.lineOverrideIsSkippedRd(l) && api.lineOverrideIsChangedRd(l)).length;
// 4 of the 7 rendered rows: the overridden one, the excluded one and the 2 hand-added ones. The
// not-enrolled row is neither rendered nor counted.
check('the count is taken over the rows that really render',
    countOfRows(LINES.map(l => l.line)) === 4 && RENDERED.length === 7,
    `${countOfRows(LINES.map(l => l.line))} of ${RENDERED.length}`);
check('...and a skipped row that carries an override is one of them',
    countOfRows([Object.assign({}, LINES[4].line, { override_action: 'exclude' })]) === 1);

api.setFilter('all');
const tabs = api.lineOverrideTabsHtmlRd(2);
check('nothing changed -> no tab row at all, rather than one that is hidden or disabled',
    api.lineOverrideTabsHtmlRd(0) === '' && api.lineOverrideTabsHtmlRd(0).indexOf('d-none') === -1);
check('it is the app own nav-tabs, marked for this table', tabs.indexOf('<ul class="nav nav-tabs lo-tabs" role="tablist">') === 0);
check('2 tabs, and they are buttons', (tabs.match(/<button/g) || []).length === 2 && (tabs.match(/<li class="nav-item"/g) || []).length === 2);
check('no data-bs-toggle: the click is handled here, not by the Bootstrap tab plugin',
    tabs.indexOf('data-bs-toggle') === -1 && tabs.indexOf('data-bs-target') === -1);
check('both labels come from langData', tabs.indexOf(LANG['line_override_tab_all']) !== -1
    && tabs.indexOf(LANG['line_override_tab_changed']) !== -1, tabs);
check('the count is a grey number in brackets, never a coloured badge',
    tabs.indexOf('<span class="text-muted">(2)</span>') !== -1 && tabs.indexOf('badge') === -1, tabs);
check('the first tab is the open one by default', tabs.indexOf('class="nav-link active" type="button" role="tab" data-lo-filter="all"') !== -1, tabs);
api.setFilter('changed');
const tabsChanged = api.lineOverrideTabsHtmlRd(2);
check('...and the open one follows the state, not the position',
    tabsChanged.indexOf('class="nav-link active" type="button" role="tab" data-lo-filter="changed"') !== -1
    && tabsChanged.indexOf('data-lo-filter="all"') !== -1
    && tabsChanged.indexOf('class="nav-link active" type="button" role="tab" data-lo-filter="all"') === -1, tabsChanged);
api.setFilter('all');

// Where the table builder puts it, and what it does with the filter.
check('the tab row renders ABOVE the table, in the same one write',
    tableSrc.indexOf('$wrap.html(lineOverrideTabsHtmlRd(changedCount) + `<div class="table-responsive">') !== -1, tableSrc);
check('the editable slip never counts and never renders a tab row',
    tableSrc.indexOf('const changedCount = isView') !== -1
    && tableSrc.indexOf(': 0;') !== -1, tableSrc);
check('the filter is applied where the rows of a group are chosen',
    tableSrc.indexOf('&& (!changedOnly || lineOverrideIsChangedRd(l)));') !== -1, tableSrc);
check('a group left empty by the filter renders no heading either -- same early return as before',
    tableSrc.indexOf('if (!groupLines.length && !manualOpen) return;') !== -1);
check('the 3 totals still come off the run row, so a filtered table still ends on the full pay',
    tableSrc.indexOf('lineOverrideTotalsHtmlRd(breakdownRowRd)') !== -1
    && tableSrc.indexOf('lineOverrideTotalsHtmlRd(groupLines') === -1);
// Switching tabs may not cost a request: both tabs are views of one payload that is already here.
const tabHandler = detailSource.slice(detailSource.indexOf("$(document).on('click', '.lo-mount .lo-tabs .nav-link'"));
const tabHandlerBody = tabHandler.slice(0, tabHandler.indexOf('});') + 3);
check('clicking a tab redraws from the rows the table already holds',
    tabHandlerBody.indexOf('renderLineOverrideTableRd(lineOverrideRowsRd, lineOverrideRunSettingsRd, lineOverrideHostRd.mode);') !== -1, tabHandlerBody);
check('...and fetches nothing', ['$.ajax', '$.get', 'loadSyncLineOverridesRd', 'fetch(']
    .every(needle => tabHandlerBody.indexOf(needle) === -1), tabHandlerBody);
check('clicking the tab that is already open does nothing at all',
    tabHandlerBody.indexOf('if (!filter || filter === lineOverrideViewFilterRd) return;') !== -1);
check('the filter is reset per open, so it never survives into the next employee slip',
    fn(detailSource, 'setLineOverrideHostRd').indexOf("lineOverrideViewFilterRd = 'all';") !== -1);
check('nothing persists it', ['localStorage', 'sessionStorage'].every(n => tabHandlerBody.indexOf(n) === -1)
    && detailSource.indexOf('lineOverrideViewFilterRd') !== -1);

console.log('');
console.log('-'.repeat(50));
console.log(`Passed: ${passed}, Failed: ${failed}`);
if (failed > 0) {
    console.log('SOME TESTS FAILED');
    process.exit(1);
}
console.log('ALL TESTS PASSED');
