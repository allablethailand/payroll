/**
 * 2026-09-23, ปุ่มแถว EED B1 -- eedActionButtons() (public/js/employee/detail.js) redesigned per
 * rules.md §7 L1573-1580 + demo docs/design/components.php:1060-1094: <=3 circular `.btn-icon`
 * buttons outside a row, everything else (only Edit/Delete, only while `current_installment=0`)
 * folds into one ⋮ dropdown with Delete last, under a divider, in `--c-danger`.
 *
 * Extraction technique matches tests/line_override_row_render_test.js -- eedActionButtons() has no
 * dependency other than `langData`, so no other function needs stubbing.
 *
 * Scope: markup/class/order/text only (same split as line_override_row_render_test.js's own docblock
 * -- 430px/color-contrast/dropdown-clipping are Playwright's job, not this file's).
 */
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const detailJsPath = path.join(root, 'public', 'js', 'employee', 'detail.js');
const detailSource = fs.readFileSync(detailJsPath, 'utf8');
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

const extracted = [
    'let langData = {};',
    fn(detailSource, 'eedActionButtons'),
    `module.exports = {
        eedActionButtons,
        setLang: (d) => { langData = d; },
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

// Same button-block markup this function's own class carries: "the row of visible round buttons".
const OUTER_SELECTOR = 'd-flex gap-1 justify-content-center';

function outerButtonClasses(html) {
    // Buttons that are direct children of the outer flex row, i.e. everything up to (not inside)
    // the nested <div class="dropdown">, if any.
    const dropdownIdx = html.indexOf('<div class="dropdown">');
    const outerHtml = dropdownIdx === -1 ? html : html.slice(0, dropdownIdx);
    return (outerHtml.match(/<button[^>]*class="([^"]*)"[^>]*>/g) || []);
}
function outerButtonSelectorsInOrder(html) {
    return outerButtonClasses(html).map(b => {
        const m2 = b.match(/(btn-view-eed|btn-eed-status|btn-edit-eed|btn-delete-eed)/);
        return m2 ? m2[1] : '?';
    });
}
function dropdownItemTextsInOrder(html) {
    const dropdownIdx = html.indexOf('<ul class="dropdown-menu');
    if (dropdownIdx === -1) return null;
    const ul = html.slice(dropdownIdx);
    const items = ul.match(/<li>.*?<\/li>/gs) || [];
    return items.map(li => (li.indexOf('dropdown-divider') !== -1 ? 'DIVIDER' : (li.match(/(btn-edit-eed|btn-delete-eed)/) || ['?'])[0]));
}

const row = (over) => Object.assign({ id: 42, status: 'active', current_installment: 0 }, over || {});

Object.keys(LANG).forEach((lang) => {
    console.log(`\n=== ${lang}: eedActionButtons() per rules.md §7 case matrix ===`);
    api.setLang(LANG[lang]);

    // Case 1: completed / cancelled -- View only, no ⋮ (L1580: <=3 forbids a ⋮).
    ['completed', 'cancelled'].forEach((status) => {
        const html = api.eedActionButtons(row({ status: status, current_installment: 3 }));
        check(`[${lang}] ${status}: only [ดู/View], no ⋮`,
            outerButtonSelectorsInOrder(html).join(',') === 'btn-view-eed' && html.indexOf('dropdown-toggle') === -1, html);
    });

    // Case 2: active, started (current_installment > 0) -- View/Pause/Cancel outside, no ⋮.
    {
        const html = api.eedActionButtons(row({ status: 'active', current_installment: 1 }));
        check(`[${lang}] active+started: [ดู,พัก,ยกเลิก] outside, no ⋮ (L1580)`,
            outerButtonSelectorsInOrder(html).join(',') === 'btn-view-eed,btn-eed-status,btn-eed-status'
            && html.indexOf('dropdown-toggle') === -1, html);
        const statusBtns = (html.match(/data-status="([a-z]+)"/g) || []);
        check(`[${lang}] active+started: Pause then Cancel, in that data-status order`,
            statusBtns.join(',') === 'data-status="paused",data-status="cancelled"', statusBtns);
    }

    // Case 3: paused, started -- View/Resume/Cancel outside, no ⋮.
    {
        const html = api.eedActionButtons(row({ status: 'paused', current_installment: 1 }));
        check(`[${lang}] paused+started: [ดู,ทำต่อ,ยกเลิก] outside, no ⋮`,
            outerButtonSelectorsInOrder(html).join(',') === 'btn-view-eed,btn-eed-status,btn-eed-status'
            && html.indexOf('dropdown-toggle') === -1, html);
        const statusBtns = (html.match(/data-status="([a-z]+)"/g) || []);
        check(`[${lang}] paused+started: Resume (data-status=active) then Cancel`,
            statusBtns.join(',') === 'data-status="active",data-status="cancelled"', statusBtns);
    }

    // Case 4: active, not started -- View/Pause/Cancel outside + ⋮[Edit, divider, Delete].
    {
        const html = api.eedActionButtons(row({ status: 'active', current_installment: 0 }));
        check(`[${lang}] active+notStarted: [ดู,พัก,ยกเลิก] outside`,
            outerButtonSelectorsInOrder(html).join(',') === 'btn-view-eed,btn-eed-status,btn-eed-status', html);
        check(`[${lang}] active+notStarted: has a ⋮ toggle`, html.indexOf('dropdown-toggle') !== -1);
        check(`[${lang}] active+notStarted: ⋮ menu is [Edit, DIVIDER, Delete] in that order`,
            (dropdownItemTextsInOrder(html) || []).join(',') === 'btn-edit-eed,DIVIDER,btn-delete-eed',
            dropdownItemTextsInOrder(html));
        check(`[${lang}] ⋮ toggle carries the shared "action_more" i18n key as title+aria-label`,
            html.indexOf(`title="${LANG[lang]['action_more']}"`) !== -1
            && html.indexOf(`aria-label="${LANG[lang]['action_more']}"`) !== -1, html);
        check(`[${lang}] Delete menu item carries text-danger (§7's own "ลบอยู่ล่างสุด...text-danger")`,
            html.indexOf('dropdown-item text-danger btn-delete-eed') !== -1, html);
    }

    // Case 5: paused, not started -- View/Resume/Cancel outside + ⋮[Edit, divider, Delete].
    {
        const html = api.eedActionButtons(row({ status: 'paused', current_installment: 0 }));
        check(`[${lang}] paused+notStarted: [ดู,ทำต่อ,ยกเลิก] outside`,
            outerButtonSelectorsInOrder(html).join(',') === 'btn-view-eed,btn-eed-status,btn-eed-status', html);
        check(`[${lang}] paused+notStarted: ⋮ menu is [Edit, DIVIDER, Delete] in that order`,
            (dropdownItemTextsInOrder(html) || []).join(',') === 'btn-edit-eed,DIVIDER,btn-delete-eed',
            dropdownItemTextsInOrder(html));
    }
});

console.log('\n=== §7: uniform shape, no per-action colour, everywhere ===');
api.setLang(LANG.th);
const ALL_CASES = [
    row({ status: 'completed' }), row({ status: 'cancelled' }),
    row({ status: 'active', current_installment: 0 }), row({ status: 'active', current_installment: 1 }),
    row({ status: 'paused', current_installment: 0 }), row({ status: 'paused', current_installment: 1 }),
];
ALL_CASES.forEach((r, i) => {
    const html = api.eedActionButtons(r);
    const buttons = html.match(/class="([^"]*)"/g) || [];
    check(`case ${i}: no text-info/text-warning/text-success/text-danger on any .btn-icon (only the ⋮ menu's own text-danger on the Delete <li>)`,
        !/class="btn btn-icon[^"]*(text-info|text-warning|text-success|text-danger)/.test(html), html);
    check(`case ${i}: every top-level round button is .btn-icon (not .btn-circle-action/.btn-link)`,
        (html.match(/<button[^>]*btn-view-eed/g) || []).every(b => b.indexOf('btn-icon') !== -1)
        && html.indexOf('btn-circle-action') === -1 && html.indexOf('btn-link') === -1, html);
});

console.log('\n=== data-* the delegated handlers (employee/detail.js, unchanged) read stays intact ===');
{
    const html = api.eedActionButtons(row({ id: 777, status: 'active', current_installment: 0 }));
    check('every action element (outside + inside ⋮) carries data-id="777"',
        (html.match(/data-id="777"/g) || []).length === 5, html); // view, pause, cancel, edit, delete
    check('.btn-eed-status/.btn-edit-eed/.btn-delete-eed class names are unchanged (handlers are class-delegated, see employee/detail.js:3584-3714)',
        html.indexOf('btn-eed-status') !== -1 && html.indexOf('btn-edit-eed') !== -1 && html.indexOf('btn-delete-eed') !== -1);
}

console.log('\n' + '-'.repeat(50));
console.log(`Passed: ${passed}, Failed: ${failed}`);
if (failed > 0) {
    console.log('SOME TESTS FAILED');
    process.exit(1);
}
console.log('ALL TESTS PASSED');
