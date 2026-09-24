/**
 * 2026-09-24, Round B1 (D3) -- `formatValue(key, value)`, table-column-filter.js's own opt-in split
 * between the RAW value a `mode:'server'` column filter sends back (`getColumnFilterValues()`,
 * unchanged, still whatever ends up in a checkbox's own `.val()`) and the LABEL text shown in the
 * checkbox list + searched by the panel's own search box (`.tcf-search`'s handler reads each item's
 * rendered `.text()`, i.e. the label -- confirmed by reading that handler directly, table-column-
 * filter.js's `openPanelFor()`, round B1's own S3).
 *
 * `buildFilterListItems(values, col)` is the pure (no jQuery/DOM) half of `renderList()` this option
 * actually lives in -- extracted so this test can exercise the real logic without a DOM. This repo
 * ships no jsdom (grepped node_modules -- only `jquery` itself is installed, which needs a
 * window/document to run at all), so `renderList()`'s own DOM writing (appending <label>/<input>/
 * <span> nodes) is NOT exercised here, same "markup/text only, DOM covered live" scope
 * tests/line_override_row_render_test.js already draws for the identical reason. SEARCH_MATCH below
 * mirrors the real `.tcf-search` handler's own substring predicate against `buildFilterListItems()`'s
 * own `label` field, rather than re-invoking jQuery.
 */
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const tcfPath = path.join(root, 'public', 'js', 'table-column-filter.js');
const tcfSource = fs.readFileSync(tcfPath, 'utf8');

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

const extracted = [
    fn(tcfSource, 'buildFilterListItems'),
    'module.exports = { buildFilterListItems };',
].join('\n');

const Module = require('module');
const m = new Module(tcfPath);
m._compile(extracted, tcfPath);
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

// Mirrors the real .tcf-search handler's own predicate (table-column-filter.js, openPanelFor()):
// substring match against the rendered text, case-insensitive.
const searchMatches = (item, q) => item.label.toLowerCase().indexOf(q.toLowerCase()) !== -1;

console.log('=== identity when no formatValue is given ===');
const rawOrder = ['b_raw', 'a_raw', 'c_raw'];
const identityItems = api.buildFilterListItems(rawOrder, { key: 'x' });
check('same length', identityItems.length === 3, identityItems.length);
check('label === raw for every item', identityItems.every((it, i) => it.label === rawOrder[i] && it.raw === rawOrder[i]), JSON.stringify(identityItems));
check('order preserved exactly as given (no sort applied)', identityItems.map(it => it.raw).join(',') === rawOrder.join(','), identityItems.map(it => it.raw).join(','));
check('col with formatValue explicitly undefined behaves the same as no formatValue at all',
    JSON.stringify(api.buildFilterListItems(rawOrder, { key: 'x', formatValue: undefined })) === JSON.stringify(identityItems));
check('col omitted entirely does not throw and is still identity',
    JSON.stringify(api.buildFilterListItems(rawOrder)) === JSON.stringify(identityItems));

console.log('\n=== label !== value ===');
const LABELS = { approve: 'อนุมัติ', reject: 'ไม่อนุมัติ', submit: 'ส่งอนุมัติ' };
const col = { key: 'audit_action', formatValue: (k, v) => LABELS[v] || v };
const items = api.buildFilterListItems(['reject', 'approve', 'submit'], col);
check('every raw value survives untouched', items.map(it => it.raw).slice().sort().join(',') === ['approve', 'reject', 'submit'].join(','), JSON.stringify(items));
check('every label is the translated text, not the raw enum', items.every(it => it.label === LABELS[it.raw]), JSON.stringify(items));
check('label really differs from raw for every item here', items.every(it => it.label !== it.raw), JSON.stringify(items));
check('formatValue receives the column key as its first argument', (() => {
    let seenKey = null;
    api.buildFilterListItems(['approve'], { key: 'audit_action', formatValue: (k, v) => { seenKey = k; return v; } });
    return seenKey === 'audit_action';
})());

console.log('\n=== search matches the label, not the raw value ===');
const rejectItem = items.find(it => it.raw === 'reject');
check('searching by the LABEL text finds it', searchMatches(rejectItem, 'ไม่อนุมัติ'));
check('searching by the RAW value text does NOT find it (label/raw share no substring here)', !searchMatches(rejectItem, 'reject'));
const approveItem = items.find(it => it.raw === 'approve');
check('same for the other row: label matches', searchMatches(approveItem, 'อนุมัติ'));
check('...raw text does not', !searchMatches(approveItem, 'approve'));

console.log('\n=== sorting ===');
const labelOrder = items.map(it => it.label);
const sortedLabelOrder = labelOrder.slice().sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));
check('with formatValue: items are sorted by LABEL, not the raw arrival order', labelOrder.join(',') === sortedLabelOrder.join(','), labelOrder.join(','));
// The raw arrival order ('reject','approve','submit') does NOT sort the same way its own Thai labels
// do -- a real case, not a contrived one: this is exactly what a backend's own `ORDER BY value ASC`
// on the untranslated enum column produces (PayrollRunModel::auditLogColumnValues()) once labelled
// client-side.
check('...and that really reordered something (raw arrival order was not already label-order)',
    items.map(it => it.raw).join(',') !== ['reject', 'approve', 'submit'].join(','), items.map(it => it.raw).join(','));
check('without formatValue: no sort at all, raw arrival order kept verbatim',
    identityItems.map(it => it.raw).join(',') === rawOrder.join(','));

console.log("\n=== the raw value is what still reaches the checkbox's own value (source check) ===");
check('renderList() feeds the checkbox .val() from item.raw, not item.label',
    tcfSource.indexOf('.val(item.raw)') !== -1 && tcfSource.indexOf('.val(item.label)') === -1);
check('...and the visible <span> text comes from item.label',
    tcfSource.indexOf('.text(item.label)') !== -1);

console.log('\n' + '-'.repeat(50));
console.log(`Passed: ${passed}, Failed: ${failed}`);
if (failed > 0) {
    console.log('SOME TESTS FAILED');
    process.exit(1);
}
console.log('ALL TESTS PASSED');
