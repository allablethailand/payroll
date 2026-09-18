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
const stubs = `
function escapeHtml(str) { if (str === null || str === undefined) return ''; return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function escapeAttr(str) { return escapeHtml(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
let currentLang = 'th';
let langData = {};
let lineOverrideHistoryRd = { byKey: {}, historyAvailable: true, startDate: null };
function statusBadgeHtml() { return ''; }
function payeeDescriptorHtmlRd() { return ''; }
function lineOverrideOccurrencesHtml() { return ''; }
function lineOverrideHistoryCellHtml() { return ''; }
function lineOverrideSkipEnumRd() { return null; }
function lineOverrideIsSkippedRd() { return false; }
`;

const extracted = [
    stubs,
    fn(formatSource, 'fmtNum'),
    fn(detailSource, 'lineOverrideHistoryFor'),
    fn(detailSource, 'lineOverrideHistoryValueRd'),
    fn(detailSource, 'lineOverrideMoneyClassRd'),
    fn(detailSource, 'lineOverrideComputedTextRd'),
    fn(detailSource, 'lineOverrideComputedTagHtml'),
    fn(detailSource, 'lineOverrideRowHtml'),
    `module.exports = {
        lineOverrideComputedTextRd, lineOverrideComputedTagHtml, lineOverrideRowHtml,
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
// byKey is keyed the way the real loader keys it, and only ever holds lines that really have a
// history row -- which is exactly what "this figure can be trusted" means here.
const historyWith = (originalValue) => ({
    byKey: { 'earning_deduction|LOAN': { item_code: 'LOAN', line_type: 'earning_deduction', original_value: originalValue, edits: [{}] } },
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
    check(`[${lang}] ...rendered inside the amount cell, under the figure`,
        overRow.indexOf('lo-amount-view') < overRow.indexOf('payslip-line-tag')
        && overRow.indexOf('payslip-line-tag') < overRow.indexOf('lo-action-cell'), overRow);
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
});

console.log('\n=== the table agrees with its own rows ===');
const tableSrc = fn(detailSource, 'renderLineOverrideTableRd');
const headerThs = (tableSrc.match(/<th class=/g) || []).length;
check('the header declares 5 columns', headerThs === 5, headerThs);
check('the header has exactly 1 money column', countOf(tableSrc, 'col-money') === 1, countOf(tableSrc, 'col-money'));
check('the retired calculated column is gone from the header', tableSrc.indexOf('lo-computed-col') === -1);
check('the group row spans all 5 columns', tableSrc.indexOf('colspan="5"') !== -1);
check('no colspan is left at the old 6', detailSource.indexOf('colspan="6"') === -1);
check('the hidden-rows row spans all 5 columns', fn(detailSource, 'lineOverrideHiddenRowHtml').indexOf('colspan="5"') !== -1);

console.log('\n=== the form hint and the sub-line are the same answer ===');
// They were already one function; what matters is that neither grew its own second opinion, because
// "the hint said 28,500 and Use-calculated gave 0.00" is exactly how the BACKLOG bug reads.
const hintSrc = fn(detailSource, 'renderLineFormComputedHintRd');
check('the form hint reads the shared builder', hintSrc.indexOf('lineOverrideComputedTextRd(') !== -1);
check('...and hides itself when there is no figure, rather than printing one', hintSrc.indexOf("if (!text)") !== -1);
check('nothing renders the retired "-" placeholder any more', detailSource.indexOf('lo-computed-unknown') === -1);

console.log('\n' + '-'.repeat(50));
console.log(`Passed: ${passed}, Failed: ${failed}`);
if (failed > 0) {
    console.log('SOME TESTS FAILED');
    process.exit(1);
}
console.log('ALL TESTS PASSED');
