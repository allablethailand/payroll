/**
 * renderCommentList() / commentComposerHtml() (public/js/app.js) -- the shared Comment list +
 * Composer component pair (docs/design/rules.md §6's own "Comment list + Composer" section).
 *
 * Originally written 2026-09-14 to prove ONE real bug fix (an empty `items` array used to return a
 * valid-but-blank `<ul class="comment-list"></ul>`, relying entirely on its one real caller to check
 * length first). 2026-09-15 (reference-driven restyle) it grew to cover the restyle's own structural
 * contract too, since that contract is exactly the kind of thing a later edit can quietly break:
 * the 3-row item shape, `bodyHtml` replacing the WHOLE item (not just its text row), and the
 * composer's own id/name derivation (which §9's dirty guard depends on to tell 2 open boxes apart).
 *
 * Same convention as this project's own tests/*.php (no test framework, plain PASS/FAIL lines,
 * nonzero exit code on any failure) and the same real-source-extraction technique
 * tests/user_agent_parser_test.js established (see that file's own docblock for why: app.js can't
 * just be require()'d, it's full of top-level jQuery/DOM calls that assume a browser).
 *
 * Scope: markup/branching only. Visual result (border colors, focus ring, spacing, light/dark) is
 * verified against the live app with Playwright in the same round -- a string test can't see those.
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

// Minimal, non-jQuery stand-ins for the real dependencies. escapeHtml/escapeAttr need a genuinely
// working (if simplified) implementation, since the functions under test call them on every piece of
// caller data. The other 4 are rendered as recognizable markers instead of real markup so an
// assertion can prove WHERE each one's output landed (and, for the empty-list case below, that the
// per-item ones were never called at all).
const stubs = `
function escapeHtml(str) { if (str === null || str === undefined) return ''; return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function escapeAttr(str) { return escapeHtml(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
let calls = { avatar: 0, badge: 0, relTime: 0, fullTime: 0 };
function apvAvatarHtml(name, size) { calls.avatar++; return '<!--avatar:' + name + ':' + size + '-->'; }
function statusBadgeHtml(enumKey, context, opts) { calls.badge++; return '<span class="badge badge-' + (STUB_TONES[enumKey] || 'neutral') + ((opts && opts.outline) ? ' badge-outline' : '') + '" data-enum="' + enumKey + '" data-context="' + context + '" data-i18n="' + enumKey + '_label">' + enumKey + '</span>'; }
const STUB_TONES = { none: 'neutral', in_progress: 'warning', completed: 'success', error: 'danger' };
function getStatusMapEntry(enumKey) { return { tone: STUB_TONES[enumKey] || 'neutral', label_key: enumKey + '_label' }; }
function getLangValue(key) { return key; }
function formatRelativeTime(t) { calls.relTime++; return 'REL(' + t + ')'; }
function formatDisplayDateTime(t) { calls.fullTime++; return 'FULL(' + t + ')'; }
function resetCalls() { calls = { avatar: 0, badge: 0, relTime: 0, fullTime: 0 }; }
`;

const extracted = stubs + '\n' +
    extractFunctionSource(source, 'emptyStateHtml') + '\n' +
    extractFunctionSource(source, 'badgeDropdownHtml') + '\n' +
    extractFunctionSource(source, 'commentComposerHtml') + '\n' +
    extractFunctionSource(source, 'renderCommentList') + '\n' +
    'module.exports = { emptyStateHtml, badgeDropdownHtml, commentComposerHtml, renderCommentList, getCalls: () => calls, resetCalls };';

const Module = require('module');
const m = new Module(appJsPath);
m._compile(extracted, appJsPath);
const { badgeDropdownHtml, commentComposerHtml, renderCommentList, getCalls, resetCalls } = m.exports;

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

console.log('=== renderCommentList() -- empty-items handling (real bug, fixed 2026-09-14) ===');

let threw = false;
let emptyArrResult = '';
resetCalls();
try {
    emptyArrResult = renderCommentList([]);
} catch (e) {
    threw = true;
    console.log('    (threw: ' + e.message + ')');
}
check('renderCommentList([]) does not throw', !threw);
check('renderCommentList([]) returns emptyStateHtml() output (has .empty-state, not a bare <ul>)', emptyArrResult.indexOf('class="empty-state"') !== -1);
check('renderCommentList([]) does NOT return an empty <ul class="comment-list">', emptyArrResult.indexOf('<ul class="comment-list">') === -1);
check('renderCommentList([]) never calls the per-item helpers (avatar/badge/time)', getCalls().avatar === 0 && getCalls().badge === 0 && getCalls().relTime === 0);

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

console.log('\n=== renderCommentList() -- 3-row item shape (§6 restyle, 2026-09-15) ===');

resetCalls();
const oneItem = renderCommentList([{
    time: '2026-09-14 15:40:00',
    timeSuffix: '(แก้ไขแล้ว)',
    actor: { name: 'สมชาย ทำเงินเดือน', avatar: null },
    text: 'บรรทัดแรก\nบรรทัดสอง',
    badge: { enum: 'completed', context: 'employee_comment_tag' },
    actions: '<button class="btn-icon-ghost comment-item-icon-btn"></button>',
}]);
check('item renders all 3 rows (head / text / foot)', oneItem.indexOf('comment-item-head') !== -1 && oneItem.indexOf('comment-item-text') !== -1 && oneItem.indexOf('comment-item-foot') !== -1);
check('avatar is rendered at 28px, in its own gutter element (not inside the head row)', oneItem.indexOf('<div class="comment-item-avatar"><!--avatar:สมชาย ทำเงินเดือน:28--></div>') !== -1);
check('the avatar gutter is a SIBLING of the content column, not a row inside it', oneItem.indexOf('comment-item-avatar') < oneItem.indexOf('comment-item-body'));
check('all 3 rows live inside .comment-item-body (one shared left edge, right of the avatar)', /comment-item-body[\s\S]*comment-item-head[\s\S]*comment-item-text[\s\S]*comment-item-foot/.test(oneItem));
check('the avatar is NOT inside the head row any more (it is a gutter, nothing sits under it)', !/comment-item-head[\s\S]{0,80}comment-item-avatar/.test(oneItem));
check('name and tag badge both live on the head row', oneItem.indexOf('comment-item-name') !== -1 && oneItem.indexOf('data-enum="completed"') !== -1);
check('time is relative, with the full date/time as a title tooltip', oneItem.indexOf('REL(2026-09-14 15:40:00)') !== -1 && oneItem.indexOf('title="FULL(2026-09-14 15:40:00)"') !== -1);
check('timeSuffix renders in its own span after the time', oneItem.indexOf('comment-item-time-suffix') !== -1 && oneItem.indexOf('(แก้ไขแล้ว)') !== -1);
check('actions render inside the foot row, AFTER the time (icons right, time left)', oneItem.indexOf('comment-item-time') < oneItem.indexOf('comment-item-actions'));
check('actions are NOT inside the head row any more', oneItem.indexOf('comment-item-actions') > oneItem.indexOf('comment-item-text'));
check('the comment text is escaped, and its real newline is preserved as-is (CSS pre-line, no <br>)', oneItem.indexOf('บรรทัดแรก\nบรรทัดสอง') !== -1 && oneItem.indexOf('<br>') === -1);

const noTagItem = renderCommentList([{ time: '2026-09-14 10:00:00', actor: { name: 'A' }, text: 'x' }]);
check('an item with no badge renders NO badge element at all (not a gray "no tag" one)', noTagItem.indexOf('class="badge') === -1);
check('an item with no actions renders no actions container', noTagItem.indexOf('comment-item-actions') === -1);

console.log('\n=== renderCommentList() -- bodyHtml replaces the WHOLE item (inline edit) ===');

resetCalls();
const editingItem = renderCommentList([{
    time: '2026-09-14 15:40:00',
    actor: { name: 'สมชาย', avatar: null },
    text: 'ไม่ควรถูก render',
    badge: { enum: 'completed', context: 'employee_comment_tag' },
    bodyHtml: '<div class="comment-composer">INLINE_EDIT_BOX</div>',
}]);
check('bodyHtml item renders the caller HTML', editingItem.indexOf('INLINE_EDIT_BOX') !== -1);
check('bodyHtml item gets the .comment-item-editing marker class', editingItem.indexOf('comment-item-editing') !== -1);
check('bodyHtml item renders NO head row (the composer has its own author row)', editingItem.indexOf('comment-item-head') === -1);
check('bodyHtml item renders NO foot row (no time/actions around a box being edited)', editingItem.indexOf('comment-item-foot') === -1);
check('bodyHtml item ignores text/badge entirely', editingItem.indexOf('ไม่ควรถูก render') === -1 && editingItem.indexOf('data-enum="completed"') === -1);
check('bodyHtml item never calls the per-item avatar/badge/time helpers', getCalls().avatar === 0 && getCalls().badge === 0 && getCalls().relTime === 0);

console.log('\n=== commentComposerHtml() -- structure + id derivation ===');

const composer = commentComposerHtml({
    idPrefix: 'employeeComment',
    actor: { name: 'สมหญิง ฝ่ายบุคคล', avatar: 'uploads/x.jpg' },
    placeholder: 'เขียนคอมเมนต์...',
    // 'none' carries `outline` exactly like the real caller passes it (payroll/detail.js's own
    // EMPLOYEE_COMMENT_TAGS) -- an untagged composer's toggle must read as an empty control.
    tags: [{ value: '', enum: 'none', outline: true }, { value: 'in_progress', enum: 'in_progress' }],
    tagContext: 'employee_comment_tag',
    actions: '<button id="btnAddEmployeeComment" disabled></button>',
});
check('composer renders the box + head + foot structure', composer.indexOf('class="comment-composer"') !== -1 && composer.indexOf('comment-composer-head') !== -1 && composer.indexOf('comment-composer-foot') !== -1);
check('composer head shows a 28px avatar and the author name', composer.indexOf(':28-->') !== -1 && composer.indexOf('สมหญิง ฝ่ายบุคคล') !== -1);
check('textarea id AND name are both derived from idPrefix (dirty-guard keys off name/id)', composer.indexOf('id="employeeCommentText"') !== -1 && composer.indexOf('name="employeeCommentText"') !== -1);
check('textarea uses the composer class, never Bootstrap .form-control (the box IS the frame)', composer.indexOf('class="comment-composer-text"') !== -1 && composer.indexOf('form-control') === -1);
check('placeholder comes from the caller, not from any langData lookup inside the component', composer.indexOf('placeholder="เขียนคอมเมนต์..."') !== -1);
check('tag choices render through statusBadgeHtml() with the caller-supplied context', composer.indexOf('data-context="employee_comment_tag"') !== -1);
check('the tag control is ONE badge dropdown, not a row of 4 chips', composer.indexOf('badge-dropdown-toggle') !== -1 && composer.indexOf('comment-tag-picker') === -1);
check('the tag value rides in a hidden input named from idPrefix (dirty-guard/form read)', composer.indexOf('<input type="hidden" name="employeeCommentTag"') !== -1);
check('the "no tag" choice is current by default, and renders the toggle as OUTLINE', /class="badge badge-neutral badge-outline dropdown-toggle badge-dropdown-toggle"/.test(composer));
check('the current choice is marked .is-selected in the menu', composer.indexOf('data-value="" data-outline="1" aria-selected="true"') !== -1 && composer.indexOf('is-selected') !== -1);
check('there is NO "แท็ก" label in front of the tag control any more', composer.indexOf('แท็ก') === -1);
check('caller-supplied action button lands in the actions slot', composer.indexOf('comment-composer-actions') !== -1 && composer.indexOf('btnAddEmployeeComment') !== -1);

const editComposer = commentComposerHtml({
    idPrefix: 'employeeCommentEdit42',
    actor: { name: 'สมชาย' },
    text: 'ข้อความเดิม',
    tag: 'in_progress',
    tags: [{ value: '', enum: 'none' }, { value: 'in_progress', enum: 'in_progress' }],
    tagContext: 'employee_comment_tag',
    textareaClass: 'employee-comment-inline-edit-text',
    textareaAttrs: 'data-id="42"',
    actions: '<button class="btn-save-inline-comment-edit"></button><button class="btn-cancel-inline-comment-edit"></button>',
});
check('an inline-edit composer uses its own id prefix (2 boxes can coexist without colliding)', editComposer.indexOf('id="employeeCommentEdit42Text"') !== -1 && editComposer.indexOf('name="employeeCommentEdit42Tag"') !== -1);
check('inline-edit composer prefills the existing comment text', editComposer.indexOf('>ข้อความเดิม</textarea>') !== -1);
check('inline-edit composer preselects the comment own current tag on the toggle, not "none"', editComposer.indexOf('class="badge badge-warning dropdown-toggle badge-dropdown-toggle"') !== -1);
check('inline-edit composer marks that same choice selected in the menu', editComposer.indexOf('data-value="in_progress" data-outline="0" aria-selected="true"') !== -1);
check('inline-edit composer hidden input carries the current value', editComposer.indexOf('name="employeeCommentEdit42Tag" id="employeeCommentEdit42Tag" value="in_progress"') !== -1);
check('textareaClass/textareaAttrs reach the textarea (delegated-handler hooks)', editComposer.indexOf('employee-comment-inline-edit-text') !== -1 && editComposer.indexOf('data-id="42"') !== -1);
check('inline-edit composer renders both of the caller own buttons', editComposer.indexOf('btn-save-inline-comment-edit') !== -1 && editComposer.indexOf('btn-cancel-inline-comment-edit') !== -1);

const bareComposer = commentComposerHtml({ idPrefix: 'x' });
check('a composer with no actor/tags/actions still renders a usable box (no crash, no stray markup)', bareComposer.indexOf('class="comment-composer"') !== -1 && bareComposer.indexOf('id="xText"') !== -1);

console.log('\n=== badgeDropdownHtml() -- 2 modes (§5) ===');

const pickerHtml = badgeDropdownHtml({
    enum: 'none', context: 'employee_comment_tag', outline: true, name: 'demoTag', value: '',
    options: [
        { value: '', enum: 'none', outline: true },
        { value: 'in_progress', enum: 'in_progress' },
        { value: 'completed', enum: 'completed' },
        { value: 'error', enum: 'error' },
    ],
});
check('picker: toggle is a real <button> badge carrying the dropdown-toggle caret class', /<button type="button" class="badge badge-neutral badge-outline dropdown-toggle badge-dropdown-toggle"/.test(pickerHtml));
check('picker: toggle is wired to Bootstrap dropdown inside a .dropdown wrapper', pickerHtml.indexOf('data-bs-toggle="dropdown"') !== -1 && pickerHtml.indexOf('class="dropdown d-inline-block badge-dropdown"') !== -1);
check('picker: every menu row is a real <button class="dropdown-item"> (that is where keyboard support comes from)', (pickerHtml.match(/<button type="button" class="dropdown-item badge-dropdown-item/g) || []).length === 4);
check('picker: every menu row renders its own badge as OUTLINE (a choice, not a state)', (pickerHtml.match(/badge-outline/g) || []).length === 5);
check('picker: exactly one row is marked selected, and it is the current value', (pickerHtml.match(/is-selected/g) || []).length === 1 && pickerHtml.indexOf('data-value="" data-outline="1" aria-selected="true"') !== -1);
check('picker: every row carries a check element (CSS hides it unless selected, so rows never resize)', (pickerHtml.match(/badge-dropdown-check/g) || []).length === 4);
check('picker: hidden input holds the value under the caller-supplied name', pickerHtml.indexOf('<input type="hidden" name="demoTag" id="demoTag" value="">') !== -1);

const pickerWithValue = badgeDropdownHtml({
    enum: 'error', context: 'employee_comment_tag', name: 'demoTag2', value: 'error',
    options: [{ value: '', enum: 'none', outline: true }, { value: 'error', enum: 'error' }],
});
check('picker: a non-default current value drives the toggle tone and is NOT outline', pickerWithValue.indexOf('class="badge badge-danger dropdown-toggle badge-dropdown-toggle"') !== -1);
check('picker: hidden input reflects that value', pickerWithValue.indexOf('value="error">') !== -1);

const actionMenu = badgeDropdownHtml({
    enum: 'completed', context: 'employee_comment_tag',
    menuHtml: '<li><button type="button" class="dropdown-item btn-verify-employee">Unverify</button></li>',
});
check('action mode: renders the caller own <li> markup verbatim', actionMenu.indexOf('btn-verify-employee') !== -1);
check('action mode: renders NO hidden input and NO value rows (nothing is being picked)', actionMenu.indexOf('<input type="hidden"') === -1 && actionMenu.indexOf('badge-dropdown-item') === -1);

// The real statusBadgeHtml() isn't extracted here (it needs STATUS_MAP), so this asserts the SOURCE
// delegation instead: its {menu} branch must call badgeDropdownHtml() rather than build a second
// copy of the same dropdown markup (which is the whole point of extracting that helper).
check('statusBadgeHtml(enum, context, {menu}) routes through badgeDropdownHtml(), no duplicate markup', (function () {
    const src = source.slice(source.indexOf('function statusBadgeHtml('), source.indexOf('function badgeDropdownHtml('));
    return src.indexOf('badgeDropdownHtml({') !== -1 && src.indexOf('<ul class="dropdown-menu">') === -1;
})());

console.log('\n' + '-'.repeat(50));
console.log(`Passed: ${passed}, Failed: ${failed}`);
if (failed > 0) {
    console.log('SOME TESTS FAILED');
    process.exit(1);
} else {
    console.log('ALL TESTS PASSED');
}
