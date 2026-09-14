/**
 * 2026-09-14, real bug found and fixed while reviewing #employeeCommentModal -- renderCommentList()
 * (public/js/app.js) used to return a valid-but-blank `<ul class="comment-list"></ul>` for an empty
 * `items` array, relying entirely on its one real caller (payroll/detail.js) to check length BEFORE
 * ever calling it. Fixed at the source: renderCommentList() now owns the empty case itself, via
 * emptyStateHtml(). This is a lightweight PASS/FAIL script proving that fix, matching this project's
 * own tests/*.php convention (no test framework, plain PASS/FAIL lines, nonzero exit code on any
 * failure) and the same real-source-extraction technique tests/user_agent_parser_test.js already
 * established (see that file's own docblock for why: app.js can't just be require()'d directly, it's
 * full of top-level jQuery/DOM calls that assume a browser).
 *
 * Scope: this file only proves the EMPTY-array branch decision (does it call emptyStateHtml(), with
 * what config, and does it never touch the other real per-item helpers while doing so) -- it does
 * NOT re-verify the real avatar/badge/time markup for a non-empty list (those helpers are stubbed
 * here to THROW specifically so an accidental call proves itself; a real golden-path render needs
 * jQuery/a DOM to exercise honestly). The non-empty, real-content case is already covered end-to-end
 * by this round's own Playwright verification against the live app.
 */
const fs = require('fs');
const path = require('path');

const appJsPath = path.join(__dirname, '..', 'public', 'js', 'app.js');
const source = fs.readFileSync(appJsPath, 'utf8');

function extractFunctionSource(fileText, fnName) {
    const marker = `function ${fnName}(`;
    const startIdx = fileText.indexOf(marker);
    if (startIdx === -1) {
        throw new Error(`${fnName}() not found in app.js -- has it been renamed/removed?`);
    }
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
    if (depth !== 0) {
        throw new Error(`${fnName}() -- could not find a matching closing brace (naive scan failed)`);
    }
    return fileText.slice(startIdx, i + 1);
}

// Minimal, non-jQuery stand-ins for renderCommentList()'s real dependencies. escapeHtml/escapeAttr
// need a genuinely working (if simplified) implementation since emptyStateHtml() itself calls them
// for the empty-state's own icon/title/text. The PER-ITEM helpers (apvAvatarHtml/statusBadgeHtml/
// formatRelativeTime/formatDisplayDateTime) are deliberately made to THROW instead -- a correctly
// short-circuited empty-array call should never reach the forEach body that would call them, so a
// throw here is itself a real assertion, not just a crash-avoidance shim.
const stubs = `
function escapeHtml(str) { if (str === null || str === undefined) return ''; return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function escapeAttr(str) { return escapeHtml(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
function apvAvatarHtml() { throw new Error('apvAvatarHtml() must not be called when items is empty'); }
function statusBadgeHtml() { throw new Error('statusBadgeHtml() must not be called when items is empty'); }
function formatRelativeTime() { throw new Error('formatRelativeTime() must not be called when items is empty'); }
function formatDisplayDateTime() { throw new Error('formatDisplayDateTime() must not be called when items is empty'); }
`;

const extracted = stubs + '\n' +
    extractFunctionSource(source, 'emptyStateHtml') + '\n' +
    extractFunctionSource(source, 'renderCommentList') + '\n' +
    'module.exports = { emptyStateHtml, renderCommentList };';

const Module = require('module');
const m = new Module(appJsPath);
m._compile(extracted, appJsPath);
const { renderCommentList } = m.exports;

let passed = 0;
let failed = 0;
function check(label, cond) {
    if (cond) {
        passed++;
        console.log(`  PASS  ${label}`);
    } else {
        failed++;
        console.log(`  FAIL  ${label}`);
    }
}

console.log('=== renderCommentList() -- empty-items handling (real bug, now fixed) ===');

let threw = false;
let emptyArrResult = '';
try {
    emptyArrResult = renderCommentList([]);
} catch (e) {
    threw = true;
    console.log('    (threw: ' + e.message + ')');
}
check('renderCommentList([]) does not throw (per-item helpers correctly never called)', !threw);
check('renderCommentList([]) returns emptyStateHtml() output (has .empty-state, not a bare <ul>)', emptyArrResult.indexOf('class="empty-state"') !== -1);
check('renderCommentList([]) does NOT return an empty <ul class="comment-list">', emptyArrResult.indexOf('<ul class="comment-list">') === -1);

const nullResult = renderCommentList(null);
check('renderCommentList(null) also returns empty-state, not a crash', nullResult.indexOf('class="empty-state"') !== -1);

const undefinedResult = renderCommentList(undefined);
check('renderCommentList(undefined) also returns empty-state', undefinedResult.indexOf('class="empty-state"') !== -1);

const customTitle = 'ยังไม่มีคอมเมนต์';
const customResult = renderCommentList([], { emptyState: { icon: 'fa-solid fa-comments', title: customTitle } });
check('renderCommentList([], {emptyState}) uses the CALLER-supplied title, not the generic default', customResult.indexOf(customTitle) !== -1);
check('renderCommentList([], {emptyState}) does not fall back to the generic "No comments yet." when a custom title was given', customResult.indexOf('No comments yet.') === -1);

const defaultResult = renderCommentList([]);
check('renderCommentList([]) with no options falls back to a real, non-empty default title', defaultResult.indexOf('class="empty-state-title"') !== -1 && defaultResult.indexOf('No comments yet.') !== -1);

console.log('\n' + '-'.repeat(50));
console.log(`Passed: ${passed}, Failed: ${failed}`);
if (failed > 0) {
    console.log('SOME TESTS FAILED');
    process.exit(1);
} else {
    console.log('ALL TESTS PASSED');
}
