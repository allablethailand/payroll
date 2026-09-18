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
 * 2026-09-18, tiny-L5: the same sub-line now also carries "งวด n/m" in front of the destination, and
 * the 3 surfaces above became 2 wrappers + 1 (the recurring card renders the payee TEXT inside its
 * own sentence, with no tag around it) -- so what is compared for byte-equality is the payee HALF of
 * the line, which is the half all 3 really share.
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
function statusBadgeHtml() { return ''; }
// 2026-09-18, 4a-1: the read-only slip IS this table now, so its row builder is the surface under
// test here. Only its payee sub-line matters to this file -- its 3 OTHER sub-lines, its badges and
// its history cell render as nothing, so payeeLineOf() reads the one tag this file is about.
function lineOverrideSkipEnumRd() { return null; }
function lineOverrideIsSkippedRd() { return false; }
function lineOverrideMoneyClassRd() { return 'money-deduction'; }
function lineOverrideComputedTagHtml() { return ''; }
function lineOverrideOccurrencesHtml() { return ''; }
function lineOverrideHistoryCellHtml() { return ''; }
function lineOverrideExemptTextRd() { return ''; }
function formulaTagTextRd() { return ''; }
function lineOverrideNoteTextRd() { return ''; }
`;

const extracted = [
    stubs,
    fn(formatSource, 'fmtNum'),
    fn(appSource, 'splitOptionCodePrefix'),
    lineDecl(detailSource, 'PAYEE_DESCRIPTOR_SEP_RD'),
    lineDecl(detailSource, 'LINE_TAG_SEP_RD'),
    lineDecl(detailSource, 'PAYEE_MASK_SHORT_RD'),
    fn(detailSource, 'rowOptionLabelRd'),
    fn(detailSource, 'payeeNameFromLabelRd'),
    fn(detailSource, 'manualLinePayeeNameRd'),
    fn(detailSource, 'payeeDescriptorShortMaskRd'),
    fn(detailSource, 'payeeDescriptorDropAccountNameRd'),
    fn(detailSource, 'payeeDescriptorNeedsReviewRd'),
    fn(detailSource, 'payeeDescriptorTextRd'),
    fn(detailSource, 'lineInstallmentTextRd'),
    fn(detailSource, 'payeeDescriptorHtmlRd'),
    // The real call sites, so what is compared below is what each of them really renders.
    // 2026-09-18, 4a-2: a hand-added line is no longer a surface of its own -- it is a ROW of the
    // slip, mapped into one by manualLineToTableRowRd(), so the 3rd renderer is gone and what used to
    // be "do the 3 agree?" is now "does the one renderer treat both kinds of row the same way?".
    fn(detailSource, 'lineOverrideTagHtmlRd'),
    fn(detailSource, 'lineOverrideRowHtml'),
    fn(detailSource, 'manualLineToTableRowRd'),
    fn(detailSource, 'recurringDestPayeeSummary'),
    `module.exports = {
        payeeDescriptorTextRd, payeeDescriptorHtmlRd, lineInstallmentTextRd,
        LINE_TAG_SEP_RD, PAYEE_MASK_SHORT_RD,
        payeeDescriptorShortMaskRd, payeeDescriptorDropAccountNameRd,
        lineOverrideRowHtml, manualLineToTableRowRd, recurringDestPayeeSummary,
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
// The sub-line only: the last `.payslip-line-tag` of a rendered row's name cell.
function tagLineOf(rowHtml) {
    const matches = rowHtml.match(/<div class="payslip-line-tag[^"]*"[^>]*>[\s\S]*?<\/div>/g);
    return matches ? innerText(matches[matches.length - 1]) : '';
}
// ...and its payee HALF: what the recurring-destination card renders as plain text inside its own
// sentence, with no instalment in front of it.
// 2026-09-18, tiny-L6b (B4): the '→ ' that used to mark where the payee half started is gone, so the
// separator BETWEEN the halves is what says where one ends -- and a line with no instalment at all
// is payee from end to end.
function payeeLineOf(rowHtml) {
    const text = tagLineOf(rowHtml);
    const at = text.indexOf(api.LINE_TAG_SEP_RD);
    return at === -1 ? text : text.slice(at + api.LINE_TAG_SEP_RD.length);
}

const EMPLOYEE_PAYEE = {
    payee_type: 'employee', missing: false, payee_employee_id: 499, payee_employee_no: 'CEO',
    payee_employee_label_th: 'CEO - กฤษดา สาธุกิจชัย',
    payee_employee_label_en: 'CEO - Kridsada Satukijchai',
    payee_employee_has_bank_account: true,
    payee_employee_account_label_th: 'ธนาคารทหารไทยธนชาต • ••••••1155 (Kridsada Satukijchai)',
    payee_employee_account_label_en: 'TMBThanachart Bank • ••••••1155 (Kridsada Satukijchai)',
};
const COMPANY_PAYEE = {
    payee_type: 'company', missing: false, bank_account_id: 4,
    bank_account_label_th: 'ธนาคารกรุงศรีอยุธยา • ••••••5566 (Trandar)',
    bank_account_label_en: 'Bank of Ayudhya (Krungsri) • ••••••5566 (Trandar)',
};
const EXTERNAL_PAYEE = {
    payee_type: 'other_person', missing: false, destination_id: 268,
    destination_label_th: 'กรมบังคับคดี (ธนาคารซีไอเอ็มบีไทย)',
    // 2026-09-18, tiny-L5: the bank's own English name -- the destination's own name has no
    // English twin in `payment_destinations`, so only the half in brackets changes.
    destination_label_en: 'กรมบังคับคดี (CIMB Thai Bank)',
};
const NOT_DISBURSED_PAYEE = { payee_type: 'not_disbursed', missing: false };
const COMPANY_NO_ACCOUNT = { payee_type: 'company', missing: false, bank_account_id: null };
const KINDS = {
    employee: EMPLOYEE_PAYEE, company: COMPANY_PAYEE, other_person: EXTERNAL_PAYEE,
    not_disbursed: NOT_DISBURSED_PAYEE, 'company (no account)': COMPANY_NO_ACCOUNT,
};
// 2026-09-18, tiny-L6b (B4): the expected shortening, spelled out against THESE fixtures rather than
// borrowed from the source -- a test that builds its expectation by calling the function under test
// agrees with it whatever either of them does.
const shortMask = (label) => label.replace('••••••', '••••');
const dropName = (label) => label.replace(' (Trandar)', '');

['th', 'en'].forEach((lang) => {
    const L = LANG[lang];
    api.setLang(lang, L);

    console.log(`\n=== [${lang}] the text says the kind AND the account ===`);
    check(`[${lang}] employee: verb, name with the code stripped, then the account it is paid into`,
        api.payeeDescriptorTextRd(EMPLOYEE_PAYEE)
            === `${L['payee_transfer_tag']} ${lang === 'th' ? 'กฤษดา สาธุกิจชัย' : 'Kridsada Satukijchai'} • ${shortMask(EMPLOYEE_PAYEE['payee_employee_account_label_' + lang])}`,
        api.payeeDescriptorTextRd(EMPLOYEE_PAYEE));
    check(`[${lang}] company: says retained, then WHICH account`,
        api.payeeDescriptorTextRd(COMPANY_PAYEE) === `${L['payee_dest_retained']} • ${dropName(shortMask(COMPANY_PAYEE['bank_account_label_' + lang]))}`,
        api.payeeDescriptorTextRd(COMPANY_PAYEE));
    check(`[${lang}] other_person: says external, then which destination`,
        api.payeeDescriptorTextRd(EXTERNAL_PAYEE) === `${L['payee_dest_external']} • ${shortMask(EXTERNAL_PAYEE['destination_label_' + lang])}`,
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

    console.log(`\n=== [${lang}] ONE wrapper, and the instalment half in front of it ===`);
    Object.entries(KINDS).forEach(([kind, payee]) => {
        const tag = api.payeeDescriptorHtmlRd(payee, { variant: 'tag' });
        check(`[${lang}] ${kind}: the tag is exactly the text builder's words, with nothing added in front`,
            innerText(tag) === api.payeeDescriptorTextRd(payee), innerText(tag));
        check(`[${lang}] ${kind}: in the one class every tag under a slip line uses, with no size of its own`,
            /^<div class="payslip-line-tag( payslip-line-tag-warn)?">/.test(tag) && tag.indexOf('class="small') === -1, tag);
        const withInstallment = api.payeeDescriptorHtmlRd(payee, { variant: 'tag', installment: { n: 2, total: 12 } });
        check(`[${lang}] ${kind}: with an instalment, that half comes FIRST`,
            innerText(withInstallment)
                === api.lineInstallmentTextRd({ n: 2, total: 12 }) + api.LINE_TAG_SEP_RD + api.payeeDescriptorTextRd(payee),
            innerText(withInstallment));
    });
    check(`[${lang}] the instalment half reads out of the lang file, never a hardcoded word`,
        api.lineInstallmentTextRd({ n: 2, total: 12 })
            === L['payslip_line_installment'].replace('{n}', '2').replace('{total}', '12')
        && typeof L['payslip_line_installment'] === 'string' && L['payslip_line_installment'].length > 0);
    check(`[${lang}] an instalment with no payee at all is the whole line, separator and all left off`,
        innerText(api.payeeDescriptorHtmlRd(null, { variant: 'tag', installment: { n: 2, total: 12 } }))
            === api.lineInstallmentTextRd({ n: 2, total: 12 }));
    check(`[${lang}] a one-off assignment sends null, and null adds nothing (never "งวด 1/1")`,
        api.payeeDescriptorHtmlRd(COMPANY_PAYEE, { variant: 'tag', installment: null })
            === api.payeeDescriptorHtmlRd(COMPANY_PAYEE, { variant: 'tag' })
        && api.lineInstallmentTextRd(null) === '');
    check(`[${lang}] only the unspecified company account turns the warning colour on`,
        api.payeeDescriptorHtmlRd(COMPANY_NO_ACCOUNT, { variant: 'tag' }).indexOf('payslip-line-tag-warn') !== -1
        && api.payeeDescriptorHtmlRd(COMPANY_PAYEE, { variant: 'tag' }).indexOf('-warn') === -1);
    check(`[${lang}] the default variant is 'tag' (no opts at all)`,
        api.payeeDescriptorHtmlRd(COMPANY_PAYEE) === api.payeeDescriptorHtmlRd(COMPANY_PAYEE, { variant: 'tag' }));

    console.log(`\n=== [${lang}] a line routed nowhere renders NOTHING (2.4) ===`);
    [null, undefined, {}, { payee_type: null }, { payee_type: '' }].forEach((p, i) => {
        check(`[${lang}] case ${i + 1}: no element at all, not an empty div`,
            api.payeeDescriptorHtmlRd(p, { variant: 'tag' }) === '',
            JSON.stringify(api.payeeDescriptorHtmlRd(p, { variant: 'tag' })));
    });
    check(`[${lang}] a slip row with neither half (STUDENT_LOAN: payee_type null, no plan) grows no second line`,
        api.lineOverrideRowHtml({ code: 'STUDENT_LOAN', name_th: 'ก', name_en: 'A', current_amount: 1, payee: null, installment: null }, 0, {}, false, 'view')
            .indexOf('payslip-line-tag') === -1);
    check(`[${lang}] ...but a line that is only an instalment of a plan still gets one`,
        tagLineOf(api.lineOverrideRowHtml({ code: 'X', name_th: 'ก', name_en: 'A', current_amount: 1, payee: null, installment: { n: 3, total: 6 } }, 0, {}, false, 'view'))
            === api.lineInstallmentTextRd({ n: 3, total: 6 }));

    console.log(`\n=== [${lang}] THE POINT: the surfaces agree, byte for byte ===`);
    Object.entries(KINDS).forEach(([kind, payee]) => {
        const fromSlip = payeeLineOf(api.lineOverrideRowHtml(
            { code: 'DED', name_th: 'รายการหัก', name_en: 'Deduction', current_amount: 1000, payee: payee }, 0, {}, false, 'view'));
        // The same renderer, fed a hand-added line through its mapper -- the row a user really
        // sees in the 2 manual groups of that very table.
        const fromManual = payeeLineOf(api.lineOverrideRowHtml(api.manualLineToTableRowRd(
            { id: 1, item_type: 'deduction', item_name_th: 'รายการหัก', item_name_en: 'Deduction', item_code: 'DED', amount: 1000, payee: payee }
        ), 0, {}, false, 'view'));
        const fromCard = api.recurringDestPayeeSummary(payee);
        check(`[${lang}] ${kind}: calculated row === hand-added row === recurring card (the payee half)`,
            fromSlip === fromManual && fromManual === fromCard && fromCard.length > 0,
            `slip=${JSON.stringify(fromSlip)} manual=${JSON.stringify(fromManual)} card=${JSON.stringify(fromCard)}`);
    });
    check(`[${lang}] and none of the 3 prints an employee code where a name belongs (rules.md §5/§6)`,
        payeeLineOf(api.lineOverrideRowHtml({ code: 'D', name_th: 'x', name_en: 'x', current_amount: 1, payee: EMPLOYEE_PAYEE }, 0, {}, false, 'view')).indexOf('CEO -') === -1
        && api.recurringDestPayeeSummary(EMPLOYEE_PAYEE).indexOf('CEO -') === -1);
});

console.log('');
console.log('=== tiny-L6b (B4): shortened for a sub-line, in the ONE builder ===');
api.setLang('th', LANG.th);
check('the mask is one length whatever the account number was',
    api.payeeDescriptorShortMaskRd('x • ••••••••••1155') === 'x • ••••1155',
    api.payeeDescriptorShortMaskRd('x • ••••••••••1155'));
check('...and a number too short to have been masked at all is left alone',
    api.payeeDescriptorShortMaskRd('x • 1155') === 'x • 1155');
check('the shortened mask is the declared constant, not a literal typed twice',
    api.payeeDescriptorShortMaskRd('••••••1155') === api.PAYEE_MASK_SHORT_RD + '1155');
check('the account-name parenthetical comes off the END only',
    api.payeeDescriptorDropAccountNameRd('x • ••••1155 (Trandar)') === 'x • ••••1155');
check('...and a label not parenthesised at the end keeps every character',
    api.payeeDescriptorDropAccountNameRd('กรมบังคับคดี (ธนาคารซีไอเอ็มบีไทย) สาขา 2')
        === 'กรมบังคับคดี (ธนาคารซีไอเอ็มบีไทย) สาขา 2');
check('an external destination keeps its own bracketed bank -- only a COMPANY account drops one',
    api.payeeDescriptorTextRd(EXTERNAL_PAYEE).indexOf('(ธนาคารซีไอเอ็มบีไทย)') !== -1,
    api.payeeDescriptorTextRd(EXTERNAL_PAYEE));
check('the company line no longer repeats the company it is already about',
    api.payeeDescriptorTextRd(COMPANY_PAYEE).indexOf('(Trandar)') === -1,
    api.payeeDescriptorTextRd(COMPANY_PAYEE));
check('no surface opens the destination half with an arrow any more',
    detailSource.indexOf('LINE_TAG_PAYEE_PREFIX_RD') === -1
    && api.payeeDescriptorHtmlRd(COMPANY_PAYEE, { variant: 'tag' }).indexOf('→') === -1);

console.log('\n=== the call sites really call the one renderer (no branch left behind) ===');
['lineOverrideRowHtml'].forEach((name) => {
    const src = fn(detailSource, name);
    check(`${name}() renders its payee through payeeDescriptorHtmlRd()`, src.indexOf('payeeDescriptorHtmlRd(') !== -1);
    check(`${name}() no longer branches on payee_type itself`, src.indexOf('payee_type ===') === -1, src.indexOf('payee_type ==='));
    // 2026-09-18, tiny-L5: all 3 pass the line's own instalment as well -- a caller that forgets it
    // silently drops the "งวด n/m" half for every line it draws, and nothing else would say so.
    check(`${name}() passes the line's own instalment to it, not just its payee`,
        src.indexOf("{ variant: 'tag', installment: line.installment }") !== -1);
});
check('the retired "inline" variant has no callers left anywhere in detail.js',
    detailSource.indexOf("variant: 'inline'") === -1);
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
