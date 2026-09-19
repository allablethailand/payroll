/**
 * 2026-09-18, tiny-L6b (B3) -- the "ปรับตัวเลข" table has ONE money column, and what the system
 * calculated is a sub-line of it.
 *
 * Two things are locked here, and they are locked together because the bug they guard against is
 * the pair of them coming apart:
 *   1. the table renders exactly one money column, and the row renders exactly one money cell --
 *      a header and a row that disagree on column count produce a table that looks fine until the
 *      first row with a sub-line, and `colspan` on the group/hidden rows has to match too;
 *   2. "ระบบ: x" appears ONLY where x is a figure the engine really produced. `original_value` is a
 *      stand-in for it -- the breakdown JSON keeps only the amount AFTER an override -- so a row
 *      with no recorded history has an older OVERRIDE there, and printing it would state a
 *      calculated value that never existed ("ค่าระบบ x หลอก", BACKLOG). Such a row says nothing.
 *      This is the interim rule; when a real persisted engine amount lands (BACKLOG, tiny-C) the
 *      assertions below are what says whether it changed behaviour anywhere else.
 *
 * Wording comes from the REAL public/lang/{th,en}.json, and the functions from the real detail.js --
 * same extraction technique, and same reason for it, as tests/payee_descriptor_render_test.js.
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
const LANG = {
    th: JSON.parse(fs.readFileSync(path.join(root, 'public', 'lang', 'th.json'), 'utf8')),
    en: JSON.parse(fs.readFileSync(path.join(root, 'public', 'lang', 'en.json'), 'utf8')),
};

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

// Only what is NOT under test. fmtNum/escapeHtml need real implementations (every assertion reads
// their output); the decorations that belong to other rounds render as inert markers.
function constDeclLocal(text, name) {
    const m = text.match(new RegExp(`^const ${name} = .*;$`, 'm'));
    if (!m) throw new Error(`${name} not found -- renamed/removed?`);
    return m[0];
}

const stubs = `
function escapeHtml(str) { if (str === null || str === undefined) return ''; return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function escapeAttr(str) { return escapeHtml(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
let currentLang = 'th';
let langData = {};
let lineOverrideHistoryRd = { byKey: {}, historyAvailable: true, startDate: null };
function statusBadgeHtml() { return ''; }
function payeeDescriptorHtmlRd() { return '<div class="payslip-line-tag">PAYEE</div>'; }
// 2026-09-18, 4a-1: the 2 HTML builders behind the formula sub-line are not under test here (they
// are the same ones the retired "?" popover used) -- what IS under test is the flattening.
function buildFormulaStepsRd(formula) { return formula ? '<li class="mb-1">A × B</li><li class="mb-1">= 1.00</li>' : null; }
function explainLineNoteRd(note) { return note === 'known_note' ? '<div class="small">คำอธิบาย</div>' : null; }
function lineOverrideOccurrencesHtml() { return ''; }
function lineOverrideHistoryCellHtml() { return ''; }
// 2026-09-19, H-ui: the read-only slip's own "somebody touched this" tag -- its own round.
function lineOverrideChangeTagTextRd() { return ''; }
function lineOverrideSkipEnumRd() { return null; }
function lineOverrideIsSkippedRd() { return false; }
`;

const extracted = [
    stubs,
    fn(formatSource, 'fmtNum'),
    fn(detailSource, 'lineOverrideHistoryKeyRd'),
    fn(detailSource, 'lineOverrideHistoryRowKindRd'),
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
    constDeclLocal(detailSource, 'STATUTORY_EXEMPTION_FIELD_RD'),
    'let lineOverrideExemptionRd = null;',
    fn(detailSource, 'statutoryExemptionFieldRd'),
    fn(detailSource, 'statutoryExemptionStateRd'),
    fn(detailSource, 'statutoryExemptionInheritRd'),
    fn(detailSource, 'statutoryExemptionEffectiveRd'),
    fn(detailSource, 'statutoryExemptionChangedRd'),
    fn(detailSource, 'statutoryExemptionTagHtmlRd'),
    fn(detailSource, 'lineOverrideRowHtml'),
    `module.exports = {
        setExemption: (e) => { lineOverrideExemptionRd = e; },
        lineOverrideComputedTextRd, lineOverrideComputedTagHtml, lineOverrideRowHtml,
        formulaTagTextRd, lineOverrideExemptTextRd, lineOverrideNoteTextRd,
        setLang: (l, d) => { currentLang = l; langData = d; },
        setHistory: (h) => { lineOverrideHistoryRd = h; },
    };`,
].join('\n');

const Module = require('module');
const m = new Module(detailJsPath);
m._compile(extracted, detailJsPath);
const api = m.exports;

let passed = 0;
let failed = 0;
function check(label, cond, extra) {
    if (cond) {
        passed++;
        console.log(`  PASS  ${label}`);
    } else {
        failed++;
        console.log(`  FAIL  ${label}${extra !== undefined ? ' -- ' + extra : ''}`);
    }
}

const GROUP = { type: 'deduction', key: 'x', fallback: 'x', money: 'money-deduction' };
const line = (over) => Object.assign({
    code: 'LOAN', name_th: 'เงินกู้', name_en: 'Loan', item_type: 'deduction',
    line_type: 'earning_deduction', current_amount: 1200, override_action: null,
}, over || {});
// byKey is keyed the way the real loader keys it, and holds the RAW rows of that key, newest
// first (2026-09-19, H-ui) -- so the stand-in for the engine's figure is `old_value` of the LAST
// entry, the oldest one, which is what the grouped endpoint used to hand over as `original_value`.
const historyWith = (originalValue) => ({
    byKey: {
        'earning_deduction|LOAN': [
            { source_type: 'override', line_type: 'earning_deduction', item_code: 'LOAN', source_id: null,
              old_value: 9999, new_value: 1200, old_text: null, new_text: null },
            { source_type: 'override', line_type: 'earning_deduction', item_code: 'LOAN', source_id: null,
              old_value: originalValue, new_value: 9999, old_text: null, new_text: null },
        ],
    },
    historyAvailable: true,
    startDate: null,
});
const NO_HISTORY = { byKey: {}, historyAvailable: true, startDate: null };

const countOf = (haystack, needle) => haystack.split(needle).length - 1;

Object.keys(LANG).forEach((lang) => {
    console.log(`\n=== ${lang}: one money column, one money cell ===`);
    api.setLang(lang, LANG[lang]);
    api.setHistory(NO_HISTORY);

    const plain = api.lineOverrideRowHtml(line(), 0, GROUP, false);
    check(`[${lang}] the row renders exactly 1 money cell`, countOf(plain, 'col-money') === 1, countOf(plain, 'col-money'));
    check(`[${lang}] ...and it is the amount cell`, plain.indexOf('lo-amount-cell') !== -1);
    check(`[${lang}] the retired calculated cell is gone from the row`, plain.indexOf('lo-computed-cell') === -1);

    console.log(`\n=== ${lang}: "${(LANG[lang]['line_override_computed_inline'] || '').replace('{amount}', 'x')}" appears only where the figure is real ===`);
    // A row nobody has touched has no sub-line: its live amount IS the calculated one, and repeating
    // it under itself would be the same number twice.
    check(`[${lang}] untouched row: no sub-line`, api.lineOverrideComputedTagHtml(line()) === '');

    api.setHistory(historyWith(1500));
    const overridden = line({ override_action: 'override_amount', current_amount: 1200 });
    const tag = api.lineOverrideComputedTagHtml(overridden);
    const expected = LANG[lang]['line_override_computed_inline'].replace('{amount}', '1,500.00');
    check(`[${lang}] overridden row with history: sub-line reads "${expected}"`, tag.indexOf(expected) !== -1, tag);
    check(`[${lang}] ...in the shared quiet tag class`, tag.indexOf('payslip-line-tag') !== -1, tag);
    const overRow = api.lineOverrideRowHtml(overridden, 0, GROUP, false);
    // lastIndexOf: the name cell above carries sub-lines of its own in the same class now (4a-1),
    // so "the LAST one" is the one that belongs to the figure.
    check(`[${lang}] ...rendered inside the amount cell, under the figure`,
        overRow.indexOf('lo-amount-view') < overRow.lastIndexOf('payslip-line-tag')
        && overRow.lastIndexOf('payslip-line-tag') < overRow.indexOf('lo-action-cell'), overRow);
    check(`[${lang}] ...and the row still has exactly 1 money cell`, countOf(overRow, 'col-money') === 1);

    // An excluded row has no figure of its own to compare against, which is the case that most
    // needs the calculated one -- it is the only thing left that says what it would have been.
    const excluded = line({ override_action: 'exclude', current_amount: 0 });
    check(`[${lang}] excluded row with history: sub-line still shown`,
        api.lineOverrideComputedTagHtml(excluded).indexOf(expected) !== -1);

    // The interim rule, and the whole reason this file exists.
    api.setHistory(NO_HISTORY);
    check(`[${lang}] overridden row with NO history: no sub-line, no dash`,
        api.lineOverrideComputedTagHtml(overridden) === '', api.lineOverrideComputedTagHtml(overridden));
    api.setHistory(historyWith(null));
    check(`[${lang}] history row with no original_value: no sub-line`,
        api.lineOverrideComputedTagHtml(overridden) === '');
    // A run whose period predates the history feature has rows in byKey it must still not trust.
    api.setHistory(Object.assign(historyWith(1500), { historyAvailable: false }));
    check(`[${lang}] run predating the history feature: no sub-line`,
        api.lineOverrideComputedTagHtml(overridden) === '');

    // 2026-09-18, tiny-C: the persisted engine figure. It is the real answer, so it is used even
    // where the history has nothing at all -- and it WINS over the history where both exist, because
    // `original_value` is only ever a stand-in for it.
    const withComputed = line({ override_action: 'override_amount', current_amount: 1200, computed_amount: 1750 });
    const computedExpected = LANG[lang]['line_override_computed_inline'].replace('{amount}', '1,750.00');
    api.setHistory(NO_HISTORY);
    check(`[${lang}] persisted computed_amount, no history: sub-line reads "${computedExpected}"`,
        api.lineOverrideComputedTagHtml(withComputed).indexOf(computedExpected) !== -1, api.lineOverrideComputedTagHtml(withComputed));
    api.setHistory(historyWith(1500));
    check(`[${lang}] persisted computed_amount beats the history stand-in`,
        api.lineOverrideComputedTagHtml(withComputed).indexOf(computedExpected) !== -1
        && api.lineOverrideComputedTagHtml(withComputed).indexOf(expected) === -1);
    // A run last calculated before tiny-C has no key at all -- the fallback must still be there.
    check(`[${lang}] computed_amount null: falls back to the history, not to silence`,
        api.lineOverrideComputedTagHtml(line({ override_action: 'override_amount', current_amount: 1200, computed_amount: null })).indexOf(expected) !== -1);
    // computed_amount = 0 is a real figure (a line the engine really computed to zero), not "absent".
    check(`[${lang}] computed_amount 0 is a figure, not a missing value`,
        api.lineOverrideComputedTagHtml(line({ override_action: 'override_amount', current_amount: 1200, computed_amount: 0 }))
            .indexOf(LANG[lang]['line_override_computed_inline'].replace('{amount}', '0.00')) !== -1);
});

console.log('\n=== the table agrees with its own rows ===');
const tableSrc = fn(detailSource, 'renderLineOverrideTableRd');
const headerThs = (tableSrc.match(/<th class=/g) || []).length;
check('the header declares 5 columns', headerThs === 5, headerThs);
check('the header has exactly 1 money column', countOf(tableSrc, 'col-money') === 1, countOf(tableSrc, 'col-money'));
check('the retired calculated column is gone from the header', tableSrc.indexOf('lo-computed-col') === -1);
// 2026-09-18, 4a-1: 5 columns in the editable slip, 3 in the read-only one -- every colspan in the
// table is derived from that one number, never typed per row.
check('the group row spans whatever the mode really renders', tableSrc.indexOf('colspan="${colCount}"') !== -1);
check('...and that number is what each mode really has', tableSrc.indexOf("isView ? 3 : 5") !== -1);
check('no colspan is left at the old 6', detailSource.indexOf('colspan="6"') === -1);
check('the hidden-rows collapse is gone entirely -- a skipped row says why on the row itself',
    detailSource.indexOf('lineOverrideHiddenRowHtml') === -1 && detailSource.indexOf('lo-hidden-toggle') === -1
    && detailSource.indexOf('lo-group-skipped') === -1);

console.log('\n=== the form hint and the sub-line are the same answer ===');
// They were already one function; what matters is that neither grew its own second opinion, because
// "the hint said 28,500 and Use-calculated gave 0.00" is exactly how the BACKLOG bug reads.
const hintSrc = fn(detailSource, 'renderLineFormComputedHintRd');
check('the form hint reads the shared builder', hintSrc.indexOf('lineOverrideComputedTextRd(') !== -1);
check('...and hides itself when there is no figure, rather than printing one', hintSrc.indexOf("if (!text)") !== -1);
check('nothing renders the retired "-" placeholder any more', detailSource.indexOf('lo-computed-unknown') === -1);

console.log('');
console.log('=== 4a-1: the sub-lines under a name, in one fixed order ===');
api.setLang('th', LANG.th);
const TAGGED_LINE = {
    code: 'LOAN_REPAY', name_th: 'ผ่อนชำระ', name_en: 'Loan', current_amount: 1000,
    line_type: 'earning_deduction', item_type: 'deduction',
    formula: { type: 'anything' }, note: 'known_note', override_action: 'override_amount',
    override_note: 'ตกลงกับพนักงานแล้ว', computed_amount: 1500, is_exempted: false,
};
const taggedRow = api.lineOverrideRowHtml(TAGGED_LINE, 0, GROUP, false);
const tagOrder = ['PAYEE', 'A × B', LANG.th['note'] + ': ตกลงกับพนักงานแล้ว'];
check('payee/instalment, then the formula, then the note somebody typed',
    tagOrder.every((needle, i, all) => i === 0 || taggedRow.indexOf(needle) > taggedRow.indexOf(all[i - 1])), taggedRow);
check('the formula is ONE line: its steps joined, never a list',
    api.formulaTagTextRd(TAGGED_LINE) === 'A × B · = 1.00', api.formulaTagTextRd(TAGGED_LINE));
check('...and falls back to the line note when there is no formula, same as the retired popover did',
    api.formulaTagTextRd({ note: 'known_note' }) === 'คำอธิบาย', api.formulaTagTextRd({ note: 'known_note' }));
check('...and renders nothing at all when there is neither (no dash, no empty tag)',
    api.formulaTagTextRd({ note: null }) === ''
    && api.lineOverrideRowHtml({ code: 'X', name_th: 'x', name_en: 'x', current_amount: 1 }, 0, GROUP, false).indexOf('text-truncate') === -1);
check('the note sub-line is the OVERRIDE note, never the engine note on the line',
    api.lineOverrideNoteTextRd({ override_note: 'มือ', note: 'manually_overridden' }) === LANG.th['note'] + ': มือ'
    && api.lineOverrideNoteTextRd({ note: 'manually_overridden' }) === '');
// 2026-09-18, 4a-1 follow-up: shown in FULL, wrapping -- no clip, no tooltip. A `title` is not
// reachable at all on a phone, and these sub-lines carry the reason a figure is what it is.
check('every sub-line is the plain tag class, with no truncation and no tooltip',
    taggedRow.indexOf('<div class="payslip-line-tag">A × B · = 1.00</div>') !== -1, taggedRow);
check('...no sub-line in the row carries a title attribute or a truncate class',
    taggedRow.indexOf('payslip-line-tag text-truncate') === -1
    && taggedRow.indexOf('payslip-line-tag" title=') === -1, taggedRow);
check('...and the builder itself no longer emits either', fn(detailSource, 'lineOverrideTagHtmlRd').indexOf('title=') === -1
    && fn(detailSource, 'lineOverrideTagHtmlRd').indexOf('text-truncate') === -1);
check('an exempted line says what it would have been, through the same tag',
    api.lineOverrideExemptTextRd({ is_exempted: true, exempted_amount: 500 })
        === LANG.th['attendance_deduction_exempted_remark'].replace('{amount}', '500.00'));
check('...and a masked figure passes through it untouched, never as NaN',
    api.lineOverrideExemptTextRd({ is_exempted: true, exempted_amount: 'XXXX' }).indexOf('XXXX') !== -1);
check('no row anywhere opens a "?" popover any more',
    detailSource.indexOf('formulaButtonRd') === -1 && detailSource.indexOf('formula-info-btn') === -1);

console.log('\n=== 4a-2: the action cell of a hand-added row ===');
// Mapped exactly as manualLineToTableRowRd() hands it over.
const MANUAL_ROW = { code: 'BONUS', name_th: '\u0e42\u0e1a\u0e19\u0e31\u0e2a', name_en: 'Bonus', current_amount: 5000,
    line_type: 'manual_line', item_type: 'manual_earning', override_action: null, override_note: null,
    manual_line_id: 91, ped_type_id: 7 };
const manualEdit = api.lineOverrideRowHtml(MANUAL_ROW, 0, GROUP, false, 'edit');
const manualView = api.lineOverrideRowHtml(MANUAL_ROW, 0, GROUP, false, 'view');
const btnsOf = (html) => (html.match(/<button[^>]*class="([^"]*)"/g) || []);
check('the editable slip gives it 2 buttons, in the one action cell',
    (manualEdit.match(/<td class="lo-action-cell">[\s\S]*?<\/td>/) || [''])[0].match(/<button/g).length === 2, manualEdit);
check('...both the shared round icon button, same classes as the calculated row\'s pencil',
    btnsOf(manualEdit).every(b => b.indexOf('class="btn btn-icon') !== -1), JSON.stringify(btnsOf(manualEdit)));
check('...a pencil and a bin, in that order',
    manualEdit.indexOf('manual-line-edit-btn') !== -1 && manualEdit.indexOf('manual-line-remove-btn') !== -1
    && manualEdit.indexOf('manual-line-edit-btn') < manualEdit.indexOf('manual-line-remove-btn'));
check('...each addressed by the line id, never by item code',
    (manualEdit.match(/data-line-id="91"/g) || []).length === 2, manualEdit);
check('...and each carries a title AND an aria-label, not an icon alone',
    (manualEdit.match(/aria-label="/g) || []).length === 2 && (manualEdit.match(/title="/g) || []).length >= 2);
check('the read-only slip renders neither the cell nor the buttons',
    manualView.indexOf('lo-action-cell') === -1 && manualView.indexOf('manual-line-edit-btn') === -1
    && manualView.indexOf('manual-line-remove-btn') === -1, manualView);
check('a hand-added row never gets the override pencil, in either mode',
    manualEdit.indexOf('lo-edit-btn') === -1 && manualView.indexOf('lo-edit-btn') === -1);
check('a row with no line id of its own carries no buttons rather than broken ones',
    api.lineOverrideRowHtml(Object.assign({}, MANUAL_ROW, { manual_line_id: null }), 0, GROUP, false, 'edit')
        .indexOf('manual-line-edit-btn') === -1);

console.log('\n' + '-'.repeat(50));
console.log(`Passed: ${passed}, Failed: ${failed}`);
if (failed > 0) {
    console.log('SOME TESTS FAILED');
    process.exit(1);
}
console.log('ALL TESTS PASSED');
