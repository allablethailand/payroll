/**
 * 2026-09-18, tiny-L4 -- one payee, one string, wherever it appears.
 *
 * Three surfaces used to answer "where does this money go?" three different ways for the SAME row:
 * the read-only slip printed a bare employee_no and no account at all, the hand-added-lines block
 * printed the name plus whichever account field its own query happened to join, and the recurring-
 * destination card printed the picker's label with no verb in front. The assertion this file exists
 * for is the last one in it: the three now produce a byte-identical string.
 *
 * Wording is read from the REAL public/lang/{th,en}.json, not restated here -- a test carrying its
 * own copy of a label still passes after someone changes the real one. Both languages are run.
 *
 * Same real-source-extraction technique as tests/breakdown_slip_render_test.js (detail.js cannot be
 * require()'d -- it is full of top-level jQuery/DOM calls that assume a browser).
 *
 * Scope: markup and text only. Colour/spacing/430px are verified against the live app with
 * Playwright in the same round -- a string test cannot see those.
 */
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const detailJsPath = path.join(root, 'public', 'js', 'payroll', 'detail.js');
const detailSource = fs.readFileSync(detailJsPath, 'utf8');
const appSource = fs.readFileSync(path.join(root, 'public', 'js', 'app.js'), 'utf8');
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
// A one-line `const NAME = ...;`. fn()'s brace scan has nothing to balance in one of these.
function lineDecl(text, name) {
    const m = text.match(new RegExp(`^const ${name} = .*;$`, 'm'));
    if (!m) throw new Error(`${name} not found -- renamed/removed?`);
    return m[0];
}

// Only the dependencies that are NOT under test. escapeHtml/fmtNum need genuinely working
// implementations (every assertion reads their output); the 2 decorations render as markers.
const stubs = `
function escapeHtml(str) { if (str === null || str === undefined) return ''; return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function escapeAttr(str) { return escapeHtml(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
let currentLang = 'th';
let langData = {};
function formulaButtonRd() { return ''; }
function statusBadgeHtml() { return ''; }
const STATUTORY_NOT_ENTITLED_NOTES_RD = [];
`;

const extracted = [
    stubs,
    fn(formatSource, 'fmtNum'),
    fn(appSource, 'splitOptionCodePrefix'),
    lineDecl(detailSource, 'PAYEE_DESCRIPTOR_SEP_RD'),
    lineDecl(detailSource, 'PAYEE_DESCRIPTOR_ICONS_RD'),
    fn(detailSource, 'rowOptionLabelRd'),
    fn(detailSource, 'payeeNameFromLabelRd'),
    fn(detailSource, 'manualLinePayeeNameRd'),
    fn(detailSource, 'payeeDescriptorNeedsReviewRd'),
    fn(detailSource, 'payeeDescriptorTextRd'),
    fn(detailSource, 'payeeDescriptorHtmlRd'),
    // The 3 real call sites, so what is compared below is what each of them really renders.
    fn(detailSource, 'breakdownLineRowsRd'),
    fn(detailSource, 'manualLineTagHtml'),
    fn(detailSource, 'manualLineRowActionsHtml'),
    fn(detailSource, 'manualLineListItemHtml'),
    fn(detailSource, 'recurringDestPayeeSummary'),
    `module.exports = {
        payeeDescriptorTextRd, payeeDescriptorHtmlRd,
        breakdownLineRowsRd, manualLineListItemHtml, recurringDestPayeeSummary,
        setLang: (l, d) => { currentLang = l; langData = d; },
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
// What a browser would read out of the rendered line: tags gone, entities back.
function innerText(html) {
    return html.replace(/<[^>]*>/g, '').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').trim();
}
// The payee line only: the last <div> of a rendered row's name cell.
function payeeLineOf(rowHtml) {
    const matches = rowHtml.match(/<div class="(?:small [a-z-]+|manual-line-payee[^"]*)"[^>]*>[\s\S]*?<\/div>/g);
    return matches ? innerText(matches[matches.length - 1]) : '';
}

const EMPLOYEE_PAYEE = {
    payee_type: 'employee', missing: false, payee_employee_id: 499, payee_employee_no: 'CEO',
    payee_employee_label_th: 'CEO - กฤษดา สาธุกิจชัย',
    payee_employee_label_en: 'CEO - Kridsada Satukijchai',
    payee_employee_has_bank_account: true,
    payee_employee_account_label_th: 'ธนาคารทหารไทยธนชาต • XXXXXX1155 (Kridsada Satukijchai)',
    payee_employee_account_label_en: 'TMBThanachart Bank • XXXXXX1155 (Kridsada Satukijchai)',
};
const COMPANY_PAYEE = {
    payee_type: 'company', missing: false, bank_account_id: 4,
    bank_account_label_th: 'ธนาคารกรุงศรีอยุธยา • XXXXXX5566 (Trandar)',
    bank_account_label_en: 'Bank of Ayudhya (Krungsri) • XXXXXX5566 (Trandar)',
};
const EXTERNAL_PAYEE = {
    payee_type: 'other_person', missing: false, destination_id: 268,
    destination_label_th: 'กรมบังคับคดี (ธนาคารซีไอเอ็มบีไทย)',
    destination_label_en: 'กรมบังคับคดี (ธนาคารซีไอเอ็มบีไทย)',
};
const NOT_DISBURSED_PAYEE = { payee_type: 'not_disbursed', missing: false };
const COMPANY_NO_ACCOUNT = { payee_type: 'company', missing: false, bank_account_id: null };
const KINDS = {
    employee: EMPLOYEE_PAYEE, company: COMPANY_PAYEE, other_person: EXTERNAL_PAYEE,
    not_disbursed: NOT_DISBURSED_PAYEE, 'company (no account)': COMPANY_NO_ACCOUNT,
};

['th', 'en'].forEach((lang) => {
    const L = LANG[lang];
    api.setLang(lang, L);

    console.log(`\n=== [${lang}] the text says the kind AND the account ===`);
    check(`[${lang}] employee: verb, name with the code stripped, then the account it is paid into`,
        api.payeeDescriptorTextRd(EMPLOYEE_PAYEE)
            === `${L['payee_transfer_tag']} ${lang === 'th' ? 'กฤษดา สาธุกิจชัย' : 'Kridsada Satukijchai'} • ${EMPLOYEE_PAYEE['payee_employee_account_label_' + lang]}`,
        api.payeeDescriptorTextRd(EMPLOYEE_PAYEE));
    check(`[${lang}] company: says retained, then WHICH account`,
        api.payeeDescriptorTextRd(COMPANY_PAYEE) === `${L['payee_dest_retained']} • ${COMPANY_PAYEE['bank_account_label_' + lang]}`,
        api.payeeDescriptorTextRd(COMPANY_PAYEE));
    check(`[${lang}] other_person: says external, then which destination`,
        api.payeeDescriptorTextRd(EXTERNAL_PAYEE) === `${L['payee_dest_external']} • ${EXTERNAL_PAYEE['destination_label_' + lang]}`,
        api.payeeDescriptorTextRd(EXTERNAL_PAYEE));
    check(`[${lang}] not_disbursed: one label, nothing to append`,
        api.payeeDescriptorTextRd(NOT_DISBURSED_PAYEE) === L['payee_type_not_disbursed'],
        api.payeeDescriptorTextRd(NOT_DISBURSED_PAYEE));
    check(`[${lang}] company with no account chosen: the review warning, never a silent blank`,
        api.payeeDescriptorTextRd(COMPANY_NO_ACCOUNT) === L['payee_bank_account_needs_review'],
        api.payeeDescriptorTextRd(COMPANY_NO_ACCOUNT));
    check(`[${lang}] an employee with no account on file says so, rather than just stopping`,
        api.payeeDescriptorTextRd({ payee_type: 'employee', payee_employee_id: 7, payee_employee_no: 'EM007', payee_employee_has_bank_account: false })
            === `${L['payee_transfer_tag']} EM007 • ${L['payee_employee_no_bank_account']}`);
    check(`[${lang}] a record that is gone is named as gone, appended to what is still known`,
        api.payeeDescriptorTextRd(Object.assign({}, EMPLOYEE_PAYEE, { missing: true }))
            .endsWith(` • ${L['payee_dest_missing']}`));
    check(`[${lang}] every word of it came from the lang file`,
        [L['payee_transfer_tag'], L['payee_dest_retained'], L['payee_dest_external'],
            L['payee_type_not_disbursed'], L['payee_bank_account_needs_review'],
            L['payee_employee_no_bank_account'], L['payee_dest_missing']].every((v) => typeof v === 'string' && v.length > 0));

    console.log(`\n=== [${lang}] the 2 variants: same text, different wrapper ===`);
    Object.entries(KINDS).forEach(([kind, payee]) => {
        const tag = api.payeeDescriptorHtmlRd(payee, { variant: 'tag' });
        const inline = api.payeeDescriptorHtmlRd(payee, { variant: 'inline' });
        check(`[${lang}] ${kind}: both variants read out the same words`,
            innerText(tag) === innerText(inline) && innerText(tag) === api.payeeDescriptorTextRd(payee),
            `${innerText(tag)} / ${innerText(inline)}`);
        check(`[${lang}] ${kind}: 'tag' is the quiet sub-line with an icon`,
            /^<div class="small text-(muted|warning)"><i class="fa-solid fa-[a-z-]+ me-1"><\/i>/.test(tag), tag);
        check(`[${lang}] ${kind}: 'inline' is the hand-added-line class, no icon of its own`,
            inline.startsWith('<div class="manual-line-payee') && inline.indexOf('<i ') === -1, inline);
    });
    check(`[${lang}] only the unspecified company account turns the warning colour on, in BOTH variants`,
        api.payeeDescriptorHtmlRd(COMPANY_NO_ACCOUNT, { variant: 'tag' }).indexOf('text-warning') !== -1
        && api.payeeDescriptorHtmlRd(COMPANY_NO_ACCOUNT, { variant: 'inline' }).indexOf('manual-line-payee-warn') !== -1
        && api.payeeDescriptorHtmlRd(COMPANY_PAYEE, { variant: 'tag' }).indexOf('text-warning') === -1
        && api.payeeDescriptorHtmlRd(COMPANY_PAYEE, { variant: 'inline' }).indexOf('-warn') === -1);
    check(`[${lang}] the default variant is 'tag' (no opts at all)`,
        api.payeeDescriptorHtmlRd(COMPANY_PAYEE) === api.payeeDescriptorHtmlRd(COMPANY_PAYEE, { variant: 'tag' }));

    console.log(`\n=== [${lang}] a line routed nowhere renders NOTHING (2.4) ===`);
    [null, undefined, {}, { payee_type: null }, { payee_type: '' }].forEach((p, i) => {
        check(`[${lang}] case ${i + 1}: no element at all, not an empty div`,
            api.payeeDescriptorHtmlRd(p, { variant: 'tag' }) === '' && api.payeeDescriptorHtmlRd(p, { variant: 'inline' }) === '',
            JSON.stringify(api.payeeDescriptorHtmlRd(p, { variant: 'tag' })));
    });
    check(`[${lang}] a slip row with no payee grows no second line under its name`,
        api.breakdownLineRowsRd([{ code: 'X', name_th: 'ก', name_en: 'A', amount: 1, payee: null }], 'money-deduction')
            .indexOf('class="small text-') === -1);

    console.log(`\n=== [${lang}] THE POINT: the 3 surfaces agree, byte for byte ===`);
    Object.entries(KINDS).forEach(([kind, payee]) => {
        const fromSlip = payeeLineOf(api.breakdownLineRowsRd(
            [{ code: 'DED', name_th: 'รายการหัก', name_en: 'Deduction', amount: 1000, payee: payee }], 'money-deduction'));
        const fromManual = payeeLineOf(api.manualLineListItemHtml(
            { id: 1, item_type: 'deduction', item_name_th: 'รายการหัก', item_name_en: 'Deduction', item_code: 'DED', amount: 1000, payee: payee }, true));
        const fromCard = api.recurringDestPayeeSummary(payee);
        check(`[${lang}] ${kind}: read-only slip === hand-added line === recurring card`,
            fromSlip === fromManual && fromManual === fromCard && fromCard.length > 0,
            `slip=${JSON.stringify(fromSlip)} manual=${JSON.stringify(fromManual)} card=${JSON.stringify(fromCard)}`);
    });
    check(`[${lang}] and none of the 3 prints an employee code where a name belongs (rules.md §5/§6)`,
        payeeLineOf(api.breakdownLineRowsRd([{ code: 'D', name_th: 'x', name_en: 'x', amount: 1, payee: EMPLOYEE_PAYEE }], 'money-deduction')).indexOf('CEO -') === -1
        && api.recurringDestPayeeSummary(EMPLOYEE_PAYEE).indexOf('CEO -') === -1);
});

console.log('\n=== the 3 call sites really call the one renderer (no branch left behind) ===');
['breakdownLineRowsRd', 'manualLineListItemHtml'].forEach((name) => {
    const src = fn(detailSource, name);
    check(`${name}() renders its payee through payeeDescriptorHtmlRd()`, src.indexOf('payeeDescriptorHtmlRd(') !== -1);
    check(`${name}() no longer branches on payee_type itself`, src.indexOf('payee_type ===') === -1, src.indexOf('payee_type ==='));
});
const cardSrc = fn(detailSource, 'recurringDestPayeeSummary');
check('recurringDestPayeeSummary() is a wrapper over the same text builder', cardSrc.indexOf('payeeDescriptorTextRd(') !== -1);
check('...and keeps only the one default that is genuinely its own', cardSrc.indexOf('payee_type ===') === -1);

console.log('\n' + '-'.repeat(50));
console.log(`Passed: ${passed}, Failed: ${failed}`);
if (failed > 0) {
    console.log('SOME TESTS FAILED');
    process.exit(1);
}
console.log('ALL TESTS PASSED');
