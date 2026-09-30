/**
 * 2026-09-25, dtlang round B -- locks `dtlangShouldSkipVisibleServerSideDraw(settings, isVisible)`
 * (public/js/app.js), the pure guard `_refreshAllDataTablesLanguageInner()` uses to decide whether to
 * skip its own `table.draw(false)` for a table that is both `serverSide:true` and currently visible
 * (see that function's own I1/I2 docblock, and docs/decisions/2026-09-25-dtlang-visible-double-fetch.md
 * for the full investigation). Extracted the same brace-matching way
 * tests/run_lifecycle_date_test.js already does for 2 other app.js functions -- this repo ships no
 * jsdom, and app.js itself is full of top-level jQuery calls that would throw outside a browser.
 *
 * What this locks: the skip fires ONLY for serverSide+visible. Client tables (visible or hidden) and
 * hidden serverSide tables must all still return false (draw stays unconditional for them, unchanged
 * from before this round) -- and a `settings` shape missing `oFeatures` entirely (defensive; DataTables
 * always sets it, but this function must not throw if it's ever absent) must not throw and must not
 * skip.
 */
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const appJsPath = path.join(root, 'public', 'js', 'app.js');
const appJsSource = fs.readFileSync(appJsPath, 'utf8');

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
    fn(appJsSource, 'dtlangShouldSkipVisibleServerSideDraw'),
    'module.exports = { dtlangShouldSkipVisibleServerSideDraw };',
].join('\n');

const Module = require('module');
const m = new Module(appJsPath);
m._compile(extracted, appJsPath);
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

const CLIENT_SETTINGS = { oFeatures: { bServerSide: false } };
const SERVERSIDE_SETTINGS = { oFeatures: { bServerSide: true } };

console.log('=== client-side table -- never skipped, visible or hidden ===');
check('client + visible -> false (draw stays)', api.dtlangShouldSkipVisibleServerSideDraw(CLIENT_SETTINGS, true) === false);
check('client + hidden -> false (draw stays)', api.dtlangShouldSkipVisibleServerSideDraw(CLIENT_SETTINGS, false) === false);

console.log('\n=== serverSide table ===');
check('serverSide + visible -> true (skip -- reloadAllTablesForLanguageChange() covers it)',
    api.dtlangShouldSkipVisibleServerSideDraw(SERVERSIDE_SETTINGS, true) === true);
check('serverSide + hidden -> false (draw stays -- the H problem, not fixed this round)',
    api.dtlangShouldSkipVisibleServerSideDraw(SERVERSIDE_SETTINGS, false) === false);

console.log('\n=== defensive shapes -- must not throw, must not skip ===');
check('settings has no oFeatures at all -> false, no throw', api.dtlangShouldSkipVisibleServerSideDraw({}, true) === false);
check('settings is null -> false, no throw', api.dtlangShouldSkipVisibleServerSideDraw(null, true) === false);
check('settings is undefined -> false, no throw', api.dtlangShouldSkipVisibleServerSideDraw(undefined, true) === false);
check('oFeatures present but bServerSide missing -> false', api.dtlangShouldSkipVisibleServerSideDraw({ oFeatures: {} }, true) === false);

console.log('\n' + '-'.repeat(50));
console.log(`Passed: ${passed}, Failed: ${failed}`);
if (failed > 0) {
    console.log('SOME TESTS FAILED');
    process.exit(1);
}
console.log('ALL TESTS PASSED');
