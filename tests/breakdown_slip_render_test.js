/**
 * 2026-09-16, D1 ("สลิปที่แก้ได้") -- the Calculation Breakdown modal keeps the read-only slip for
 * every row that cannot be edited, and swaps in an editable layout for one that can.
 *
 * The point of this file is the FIRST section: the read-only slip's output is pinned, character for
 * character, against a fixture. The editable layout is the thing being built on top of it, and the
 * one regression nobody would notice is that work leaking into the read-only view every approved/
 * paid/locked run still renders with. A golden-string assertion is the only kind that catches "one
 * extra class / one moved element" in a build path made of template literals.
 *
 * The second section covers the shared payslip component's own new slots (a column-title action and
 * `showTotals: false`), since the editable layout is built entirely out of them rather than out of a
 * second copy of that markup -- the assertion that matters there is that a caller passing NEITHER
 * still gets exactly what it got before.
 *
 * Same convention as this project's own tests/*.php and the real-source-extraction technique
 * tests/user_agent_parser_test.js established (detail.js/app.js can't be require()'d -- they are
 * full of top-level jQuery/DOM calls that assume a browser).
 *
 * Scope: markup only. The visual result (spacing, the 430px single column, light/dark) is verified
 * against the live app with Playwright in the same round -- a string test can't see those.
 */
const fs = require('fs');
const path = require('path');

const detailJsPath = path.join(__dirname, '..', 'public', 'js', 'payroll', 'detail.js');
const appJsPath = path.join(__dirname, '..', 'public', 'js', 'app.js');
const detailSource = fs.readFileSync(detailJsPath, 'utf8');
const appSource = fs.readFileSync(appJsPath, 'utf8');

function extractFunctionSource(fileText, fnName, label) {
    const marker = `function ${fnName}(`;
    const startIdx = fileText.indexOf(marker);
    if (startIdx === -1) {
        throw new Error(`${fnName}() not found in ${label} -- has it been renamed/removed?`);
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
// The const the extracted functions branch on, taken from the real file rather than restated here: a
// test that keeps its own copy of the note list would still pass after someone changed the real one.
function extractConst(fileText, constName, label) {
    const re = new RegExp(`^const ${constName} = .*;$`, 'm');
    const m = fileText.match(re);
    if (!m) {
        throw new Error(`const ${constName} not found in ${label}`);
    }
    return m[0];
}

// Stubs for the dependencies that are NOT under test. escapeHtml/escapeAttr/fmtNum need genuinely
// working implementations (every assertion reads their output); the other 2 render as recognizable
// markers so an assertion can prove WHERE their output landed and that the slip still calls them.
const stubs = `
function escapeHtml(str) { if (str === null || str === undefined) return ''; return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function escapeAttr(str) { return escapeHtml(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
function fmtNum(n) { const v = Number(n || 0); return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
let currentLang = 'th';
const langData = {
    table_base_salary: 'ฐานเงินเดือน',
    breakdown_earnings: 'เงินได้',
    payslip_deductions_title: 'รายการหัก',
    payslip_total_earnings: 'รวมรายได้',
    payslip_total_deductions: 'รวมรายการหัก',
    payslip_group_statutory: 'ภาครัฐ',
    payslip_group_items: 'รายการเพิ่มเติม',
    table_net_pay: 'ยอดจ่ายสุทธิ',
    manual_line_add_earning: 'เพิ่มรายการเงินได้',
    manual_line_add_deduction: 'เพิ่มรายการหัก',
    manual_line_add_locked: 'เพิ่มรายการได้เฉพาะรอบที่เป็นฉบับร่าง และพนักงานที่ยังไม่ถูกยืนยัน',
    manual_line_form_edit_title: 'แก้ไขรายการ',
    manual_line_legacy_locked: 'รายการนี้บันทึกไว้ก่อนที่หน้านี้จะแก้ไขได้ แก้หรือลบจากที่นี่ไม่ได้',
    action_remove: 'ลบออก',
    // 2026-09-18, tiny-L4: the payee descriptor's own wording, same keys the real th.json has
    payee_transfer_tag: 'จ่ายให้',
    payee_dest_retained: 'หักเข้าบริษัท',
    payee_dest_external: 'โอนให้บุคคล/หน่วยงานภายนอก',
    payee_dest_employee: 'โอนให้พนักงานคนอื่น',
    payee_type_not_disbursed: 'หักแต่ไม่มีเงินสดเคลื่อนไหว (Write-off)',
    payee_bank_account_needs_review: 'บัญชีบริษัท -- ยังไม่ระบุบัญชี ต้องตรวจสอบ',
    payee_employee_no_bank_account: 'พนักงานคนนี้ยังไม่มีบัญชีธนาคาร',
    payee_dest_missing: 'ปลายทางนี้ถูกลบไปแล้ว',
    // 2026-09-18, tiny-L5: the other half of the same sub-line
    payslip_line_installment: 'งวด {n}/{total}'
};
let calls = { formula: 0, badge: 0 };
function formulaButtonRd() { calls.formula++; return '<!--formula-->'; }
function statusBadgeHtml(enumKey, context, opts) { calls.badge++; return '<!--badge:' + enumKey + '-->'; }
function resetCalls() { calls = { formula: 0, badge: 0 }; }
`;

const extracted = [
    stubs,
    extractFunctionSource(appSource, 'payslipNetSummaryHtml', 'app.js'),
    extractFunctionSource(appSource, 'payslipViewHtml', 'app.js'),
    extractConst(detailSource, 'STATUTORY_NOT_ENTITLED_NOTES_RD', 'detail.js'),
    // 2026-09-18, tiny-L4: the slip's payee line is rendered by the shared descriptor helper
    // now, so the pinned output below includes whatever IT produces -- pulled from the real
    // source (and from app.js for the code-prefix strip it leans on) like everything else here.
    extractFunctionSource(appSource, 'splitOptionCodePrefix', 'app.js'),
    extractConst(detailSource, 'PAYEE_DESCRIPTOR_SEP_RD', 'detail.js'),
    extractConst(detailSource, 'PAYEE_MASK_SHORT_RD', 'detail.js'),
    extractConst(detailSource, 'LINE_TAG_SEP_RD', 'detail.js'),
    extractFunctionSource(detailSource, 'rowOptionLabelRd', 'detail.js'),
    extractFunctionSource(detailSource, 'payeeNameFromLabelRd', 'detail.js'),
    extractFunctionSource(detailSource, 'manualLinePayeeNameRd', 'detail.js'),
    extractFunctionSource(detailSource, 'payeeDescriptorShortMaskRd', 'detail.js'),
    extractFunctionSource(detailSource, 'payeeDescriptorDropAccountNameRd', 'detail.js'),
    extractFunctionSource(detailSource, 'payeeDescriptorNeedsReviewRd', 'detail.js'),
    extractFunctionSource(detailSource, 'payeeDescriptorTextRd', 'detail.js'),
    extractFunctionSource(detailSource, 'lineInstallmentTextRd', 'detail.js'),
    extractFunctionSource(detailSource, 'payeeDescriptorHtmlRd', 'detail.js'),
    extractFunctionSource(detailSource, 'breakdownLineRowsRd', 'detail.js'),
    extractFunctionSource(detailSource, 'statutoryRowsRd', 'detail.js'),
    extractFunctionSource(detailSource, 'payslipEmptyRowRd', 'detail.js'),
    extractFunctionSource(detailSource, 'breakdownViewSlipHtml', 'detail.js'),
    extractFunctionSource(detailSource, 'manualLineAddButtonHtml', 'detail.js'),
    extractFunctionSource(detailSource, 'manualLineTagHtml', 'detail.js'),
    extractFunctionSource(detailSource, 'manualLineRowActionsHtml', 'detail.js'),
    extractFunctionSource(detailSource, 'manualLineListItemHtml', 'detail.js'),
    'module.exports = { breakdownViewSlipHtml, manualLineAddButtonHtml, manualLineListItemHtml, payslipViewHtml, payslipNetSummaryHtml, payeeDescriptorHtmlRd, getCalls: () => calls, resetCalls };'
].join('\n');

const Module = require('module');
const m = new Module(detailJsPath);
m._compile(extracted, detailJsPath);
const { breakdownViewSlipHtml, manualLineAddButtonHtml, manualLineListItemHtml, payslipViewHtml, payslipNetSummaryHtml, payeeDescriptorHtmlRd, getCalls, resetCalls } = m.exports;

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

// ONE fixture row covering every branch the slip has: base salary, a calculation-produced earning, a
// hand-added earning and deduction, a statutory line that applies, and one that does not (filtered).
const FIXTURE_ROW = {
    base_salary_amount: 30000,
    gross_amount: 33500,
    total_deduction_amount: 1300,
    net_amount: 32200,
    earning_breakdown: [
        { code: 'OT', name_th: 'ค่าล่วงเวลา', name_en: 'Overtime', amount: 1500, note: 'OT 10 ชม.' },
        { source: 'manual_line', manual_line_id: 146, code: 'BONUS', name_th: 'โบนัส', name_en: 'Bonus', amount: 2000, note: null, is_custom: false, is_other: false }
    ],
    deduction_breakdown: [
        { source: 'manual_line', manual_line_id: 147, code: 'UNIFORM_DEDUCT', name_th: 'หักค่าเครื่องแบบ', name_en: 'Uniform Deduction', amount: 550, note: null, is_custom: false, is_other: false, payee_type: 'company', bank_account_id: 4,
          // 2026-09-18, tiny-L4: what getDetails() now enriches every line with. The 4 raw
          // columns stay (they are what the descriptor was built FROM), and the renderer reads
          // only this.
          // 2026-09-18, tiny-L5: and what enrichLineInstallment() adds beside it -- this line is
          // the 2nd of a 12-instalment plan, so the golden below pins the combined sub-line.
          installment: { n: 2, total: 12 },
          payee: { payee_type: 'company', missing: false, bank_account_id: 4,
              bank_account_label_th: 'ธนาคารกรุงศรีอยุธยา • ••••5566',
              bank_account_label_en: 'Bank of Ayudhya (Krungsri) • ••••5566' } }
    ],
    statutory_breakdown: [
        { code: 'TH_SSO', name_th: 'ประกันสังคม', name_en: 'Social Security (SSO)', employee_amount: 750, employer_amount: 750, note: null },
        { code: 'TH_PVD', name_th: 'กองทุนสำรองเลี้ยงชีพ', name_en: 'Provident Fund', employee_amount: 0, employer_amount: 0, note: 'employee_not_enrolled' }
    ]
};

console.log('=== the read-only slip is pinned: the exact HTML, unchanged by the editable layout ===');

resetCalls();
const viewHtml = breakdownViewSlipHtml(FIXTURE_ROW);

// The fixture. Regenerating it by hand after an intentional change is the point: it forces the
// change to be looked at, rather than a diff nobody sees.
// 2026-09-18, tiny-L5 -- ONE line of it changed, deliberately: the routed deduction's sub-line. It
// was `.small.text-muted` + a per-payee-kind icon; it is now `.payslip-line-tag` (one size for every
// tag in a slip, which Bootstrap's parent-relative `.small` could not give) and it carries the
// instalment the line is part of in front of the destination. The icons went with it -- each named
// the payee kind that the words right after it already named in full.
const VIEW_FIXTURE = `<div class="payslip-view">
        <div class="payslip-columns">
            <div class="payslip-col">
                <div class="payslip-col-title">เงินได้</div>
                <table class="table table-sm payslip-line-table mb-0">
                    <tbody><tr class="payslip-row">
            <td><span title="BASE">ฐานเงินเดือน</span></td>
            <td class="text-end num money-gross">30,000.00</td>
        </tr><tr class="payslip-row">
            <td>
                <div class="payslip-line-head"><span class="payslip-line-name" title="OT">ค่าล่วงเวลา</span><!--formula--></div>
                <div class="payslip-line-note" title="OT 10 ชม.">OT 10 ชม.</div></td>
            <td class="text-end num money-gross">1,500.00</td>
        </tr><tr class="payslip-row">
            <td>
                <div class="payslip-line-head"><span class="payslip-line-name" title="BONUS">โบนัส</span><!--formula--></div>
                </td>
            <td class="text-end num money-gross">2,000.00</td>
        </tr></tbody>
                </table>
                <div class="payslip-col-total">
                    <span>รวมรายได้</span>
                    <span class="num money-gross">33,500.00</span>
                </div>
            </div>
            <div class="payslip-col">
                <div class="payslip-col-title">รายการหัก</div>
                <table class="table table-sm payslip-line-table mb-0 payslip-line-table-grouped">
                    <tbody><tr class="payslip-subgroup-row"><td colspan="2" class="payslip-subgroup-label">ภาครัฐ</td></tr><tr class="payslip-row">
                <td><span title="TH_SSO">ประกันสังคม</span><!--formula--></td>
                <td class="text-end num money-deduction">750.00</td>
            </tr><tr class="payslip-subgroup-row"><td colspan="2" class="payslip-subgroup-label">รายการเพิ่มเติม</td></tr><tr class="payslip-row">
            <td>
                <div class="payslip-line-head"><span class="payslip-line-name" title="UNIFORM_DEDUCT">หักค่าเครื่องแบบ</span><!--formula--></div>
                <div class="payslip-line-tag">งวด 2/12 · หักเข้าบริษัท • ธนาคารกรุงศรีอยุธยา • ••••5566</div></td>
            <td class="text-end num money-deduction">550.00</td>
        </tr></tbody>
                </table>
                <div class="payslip-col-total">
                    <span>รวมรายการหัก</span>
                    <span class="num money-deduction">1,300.00</span>
                </div>
            </div>
        </div>
        <div class="payslip-summary">
            <div class="payslip-summary-row payslip-summary-row-net">
                <span class="payslip-summary-label">ยอดจ่ายสุทธิ</span>
                <span class="num money-net fs-5">32,200.00</span>
            </div>
        </div>
    </div>`;

check('the read-only slip renders EXACTLY the pinned fixture (any diff below is the real one)', viewHtml === VIEW_FIXTURE);
if (viewHtml !== VIEW_FIXTURE) {
    // Printed, not swallowed: a golden test whose failure output is just "false" is a test nobody
    // can act on. First differing character + a window around it.
    let i = 0;
    while (i < viewHtml.length && i < VIEW_FIXTURE.length && viewHtml[i] === VIEW_FIXTURE[i]) i++;
    console.log(`        first difference at char ${i}`);
    console.log(`        expected: ${JSON.stringify(VIEW_FIXTURE.slice(Math.max(0, i - 40), i + 60))}`);
    console.log(`        actual  : ${JSON.stringify(viewHtml.slice(Math.max(0, i - 40), i + 60))}`);
}
// The things the fixture above would still contain if someone "fixed" it by regenerating it from a
// broken build -- stated separately so they fail loudly and by name.
check('the read-only slip carries no column-title action (that is the editable layout only)',
    viewHtml.indexOf('payslip-col-title-action') === -1 && viewHtml.indexOf('payslip-col-title-with-action') === -1);
check('the read-only slip still renders both column totals and the net band',
    (viewHtml.match(/payslip-col-total/g) || []).length === 2 && viewHtml.indexOf('payslip-summary-row-net') !== -1);
check("it still groups the deduction column the component's own way (ภาครัฐ / รายการเพิ่มเติม)",
    viewHtml.indexOf('>ภาครัฐ<') !== -1 && viewHtml.indexOf('>รายการเพิ่มเติม<') !== -1);
check('it still hides a statutory line this employee is not enrolled in', viewHtml.indexOf('กองทุนสำรองเลี้ยงชีพ') === -1);
check('it still calls formulaButtonRd() per line (4 lines: OT, BONUS, SSO, UNIFORM)', getCalls().formula === 4);

console.log('\n=== payslipViewHtml(): the net band is one implementation, not two ===');

const netOnly = payslipNetSummaryHtml(32200);
check('payslipNetSummaryHtml() renders the same band the full slip ends with',
    viewHtml.indexOf(netOnly) !== -1);
check('it takes a label override, same as the full slip does',
    payslipNetSummaryHtml(10, 'ยอดปรับสุทธิ').indexOf('ยอดปรับสุทธิ') !== -1);

console.log('\n=== payslipViewHtml(): the 2 new optional slots ===');

const plain = payslipViewHtml({ earningRowsHtml: '<tr><td>A</td></tr>', deductionItemRowsHtml: '<tr><td>B</td></tr>', grossAmount: 1, totalDeductionAmount: 2, netAmount: -1 });
check('a caller passing neither slot gets the plain title it always got',
    plain.indexOf('<div class="payslip-col-title">') !== -1 && plain.indexOf('payslip-col-title-action') === -1);
check('a caller passing neither slot still gets both totals and the net band',
    (plain.match(/payslip-col-total/g) || []).length === 2 && plain.indexOf('payslip-summary-row-net') !== -1);

const withAction = payslipViewHtml({
    earningRowsHtml: '<tr><td>A</td></tr>',
    deductionItemRowsHtml: '<tr><td>B</td></tr>',
    earningTitleActionHtml: '<!--ACTION-E-->',
    deductionTitleActionHtml: '<!--ACTION-D-->',
    showTotals: false,
});
check('a title action renders inside its own column title', withAction.indexOf('<!--ACTION-E-->') !== -1 && withAction.indexOf('<!--ACTION-D-->') !== -1);
check('a title that carries an action gets the modifier class (no :has() in the stylesheet)',
    (withAction.match(/payslip-col-title payslip-col-title-with-action/g) || []).length === 2);
check('the action is wrapped so the title can flex it to the column edge',
    (withAction.match(/<span class="payslip-col-title-action">/g) || []).length === 2);
check('showTotals:false drops BOTH per-column totals', withAction.indexOf('payslip-col-total') === -1);
check('showTotals:false drops the net band too (the caller carries its own total)', withAction.indexOf('payslip-summary') === -1);
check('showTotals:false still renders both columns and their rows',
    (withAction.match(/payslip-col"/g) || []).length === 2 && withAction.indexOf('<td>A</td>') !== -1 && withAction.indexOf('<td>B</td>') !== -1);
// The Income column never had a "-" placeholder; the Deductions one only makes sense above a total
// band. In list mode an empty column is empty on both sides, not asymmetric.
const emptyList = payslipViewHtml({ earningRowsHtml: '', deductionItemRowsHtml: '', showTotals: false });
check('showTotals:false leaves an empty Deductions column empty (no "-" the Income column never had)',
    emptyList.indexOf('>-<') === -1);
const emptyPayslip = payslipViewHtml({ earningRowsHtml: '', deductionItemRowsHtml: '', grossAmount: 0, totalDeductionAmount: 0, netAmount: 0 });
check('a real payslip still gets its "-" placeholder (the band above it needs something to sit under)',
    emptyPayslip.indexOf('>-<') !== -1);

console.log('\n=== the add-an-item button on a column head ===');

const addBtn = manualLineAddButtonHtml('earning', true);
// 2026-09-18, tiny-L6b (B5): a worded outline button, not a circled + -- on a column HEAD the only
// thing naming what the + added was its tooltip.
check('it is the worded outline action (§4), not a circle and not an icon',
    addBtn.indexOf('class="btn btn-outline-primary manual-line-add-btn"') !== -1 && addBtn.indexOf('<i ') === -1, addBtn);
check('it carries the column it sits on, which is what decides the new line type', addBtn.indexOf('data-item-type="earning"') !== -1);
check('D2: it is no longer disabled for an editable row', addBtn.indexOf('disabled') === -1);
check('its tooltip names the column it adds to', addBtn.indexOf('title="เพิ่มรายการเงินได้"') !== -1);
const addBtnDeduction = manualLineAddButtonHtml('deduction', true);
check('the deduction column head gets its own type and its own wording',
    addBtnDeduction.indexOf('data-item-type="deduction"') !== -1 && addBtnDeduction.indexOf('title="เพิ่มรายการหัก"') !== -1);
const addBtnLocked = manualLineAddButtonHtml('earning', false);
check('a frozen row gets the SAME button, disabled, with the reason in its tooltip',
    addBtnLocked.indexOf(' disabled ') !== -1 && addBtnLocked.indexOf('เพิ่มรายการได้เฉพาะรอบที่เป็นฉบับร่าง') !== -1);
check('every label comes from langData, never a hardcoded string',
    addBtn.indexOf('Add an income item') === -1 && addBtnLocked.indexOf('Items can only be added') === -1);

console.log('');
console.log('=== one hand-added row: its actions, and who gets them ===');

const MANUAL_LINE = { id: 146, item_type: 'earning', item_name_th: 'โบนัส', item_name_en: 'Bonus', item_code: 'BONUS', amount: 2000, note: null, is_custom: false, is_other: false };

const rowEditable = manualLineListItemHtml(MANUAL_LINE, true);
check('the row carries its own line id, which its pencil and bin both read',
    rowEditable.indexOf('data-line-id="146"') !== -1);
// 2026-09-17 (R1 follow-up): the class still marks a row that HAS actions -- it is no longer a press
// target itself, which is asserted on the handler side below.
check('an editable row is marked as one',
    rowEditable.indexOf('manual-line-item manual-line-item-editable') !== -1);
check('both actions are the shared 32px round button (§7), not a size or colour of their own',
    rowEditable.indexOf('class="btn-icon manual-line-edit-btn"') !== -1
    && rowEditable.indexOf('class="btn-icon manual-line-remove-btn"') !== -1);
check('the actions sit at the END of the row, in the flex wrapper beside the figure (never on the <td> itself, §7)',
    rowEditable.indexOf('<div class="manual-line-amount-wrap"><span class="manual-line-amount">2,000.00</span><span class="manual-line-actions">') !== -1);
check('the figure keeps its own money colour and .num alignment', rowEditable.indexOf('class="text-end num money-gross"') !== -1);

const rowReadOnly = manualLineListItemHtml(MANUAL_LINE, false);
check('a block that cannot be edited renders NO action slot at all',
    rowReadOnly.indexOf('manual-line-actions') === -1 && rowReadOnly.indexOf('manual-line-item-editable') === -1);
check('...and still renders the same figure', rowReadOnly.indexOf('>2,000.00<') !== -1);

const rowLegacy = manualLineListItemHtml(Object.assign({}, MANUAL_LINE, { id: null }), true);
check('a legacy line (no id of its own) gets no buttons -- there is no row for them to act on',
    rowLegacy.indexOf('manual-line-edit-btn') === -1 && rowLegacy.indexOf('manual-line-remove-btn') === -1);
check('...but keeps the slot, so the figures around it stay in one column',
    rowLegacy.indexOf('manual-line-actions manual-line-actions-empty') !== -1);
check('...and says on the row itself why it has none', rowLegacy.indexOf('รายการนี้บันทึกไว้ก่อน') !== -1);
check('...and is not marked editable at all', rowLegacy.indexOf('manual-line-item-editable') === -1);

// 2026-09-17, R1 follow-up: the pencil is the ONLY way into the edit form. A whole-row handler made
// every name, note and figure a control -- there was no way to read a row without starting an edit.
check('no handler opens the form from the row itself',
    detailSource.indexOf(".on('click', '.ml-mount .manual-line-item-editable'") === -1);
check('the pencil still does', detailSource.indexOf(".on('click', '.ml-mount .manual-line-edit-btn'") !== -1);
const styleCss = fs.readFileSync(path.join(__dirname, '..', 'public', 'css', 'style.css'), 'utf8');
check('and the row no longer advertises itself as pressable',
    /\.manual-line-item-editable\s*\{[^}]*cursor:\s*pointer/.test(styleCss) === false
    && /\.manual-line-item-editable:hover/.test(styleCss) === false);

console.log('\n=== the sub-line under a line: งวด n/m · where it goes (2026-09-18, tiny-L5) ===');

const SUB_PAYEE = { payee_type: 'other_person', missing: false, destination_id: 268,
    destination_label_th: 'กรมบังคับคดี (ธนาคารซีไอเอ็มบีไทย)',
    destination_label_en: 'กรมบังคับคดี (CIMB Thai Bank)' };
const subBoth = payeeDescriptorHtmlRd(SUB_PAYEE, { variant: 'tag', installment: { n: 2, total: 12 } });
check('both halves: the instalment, the separator, then the destination behind an arrow',
    subBoth === '<div class="payslip-line-tag">งวด 2/12 · โอนให้บุคคล/หน่วยงานภายนอก • กรมบังคับคดี (ธนาคารซีไอเอ็มบีไทย)</div>');
check('payee only: no separator and no empty half in front of it',
    payeeDescriptorHtmlRd(SUB_PAYEE, { variant: 'tag', installment: null })
    === '<div class="payslip-line-tag">โอนให้บุคคล/หน่วยงานภายนอก • กรมบังคับคดี (ธนาคารซีไอเอ็มบีไทย)</div>');
check('instalment only: the arrow belongs to the destination half, so it goes with it',
    payeeDescriptorHtmlRd(null, { variant: 'tag', installment: { n: 2, total: 12 } })
    === '<div class="payslip-line-tag">งวด 2/12</div>');
check('neither (a payee_type of null -- STUDENT_LOAN): nothing at all, not an empty row',
    payeeDescriptorHtmlRd(null, { variant: 'tag', installment: null }) === '');
check('...and a descriptor that resolved to nothing sayable is the same answer',
    payeeDescriptorHtmlRd({ payee_type: null }, { variant: 'tag' }) === '');
check('a one-off assignment never reaches the tag as "งวด 1/1" -- the server sends null, and null renders nothing',
    payeeDescriptorHtmlRd(null, { variant: 'tag', installment: null }) === '');
check('the company-account-with-no-account case still carries its warning modifier, not its own font size',
    payeeDescriptorHtmlRd({ payee_type: 'company', missing: false }, { variant: 'tag' })
    === '<div class="payslip-line-tag payslip-line-tag-warn">บัญชีบริษัท -- ยังไม่ระบุบัญชี ต้องตรวจสอบ</div>');
check('every tag under a slip line is the one class, never Bootstrap .small (whose size follows its parent)',
    subBoth.indexOf('class="small') === -1 && subBoth.indexOf('payslip-line-tag') !== -1);

// The 3 places that sub-line is drawn. Two are rendered right here; the editable table's own row
// builder needs half this file's module to run, so it is asserted at the source (same technique the
// handler assertions below/above use).
check("1/3 the read-only slip passes the line's own instalment, not just its payee",
    viewHtml.indexOf('<div class="payslip-line-tag">งวด 2/12 · หักเข้าบริษัท • ธนาคารกรุงศรีอยุธยา') !== -1);
const rowWithPayee = manualLineListItemHtml(Object.assign({}, MANUAL_LINE, {
    payee: { payee_type: 'not_disbursed', missing: false },
}), true);
check('2/3 a hand-added line uses the SAME wrapper now (.manual-line-payee is gone)',
    rowWithPayee.indexOf('<div class="payslip-line-tag">หักแต่ไม่มีเงินสดเคลื่อนไหว (Write-off)</div>') !== -1
    && rowWithPayee.indexOf('manual-line-payee') === -1);
check('3/3 the editable table draws it under the name, beside the occurrences it already had',
    /\$\{payeeDescriptorHtmlRd\(line\.payee, \{ variant: 'tag', installment: line\.installment \}\)\}\s*\n\s*\$\{lineOverrideOccurrencesHtml\(line\.occurrences\)\}/
        .test(extractFunctionSource(detailSource, 'lineOverrideRowHtml', 'detail.js')));
check('a switched-off row keeps its tag and fades it with the name -- it is not hidden',
    /\.lo-row-off \.payslip-line-tag\s*\{\s*color:\s*var\(--c-text-faint\)/.test(styleCss)
    && /\.payslip-line-tag\s*\{[^}]*font-size:\s*var\(--fs-xs\)/.test(styleCss));
check('the retired second class is gone from the stylesheet too, not just unused',
    /^\.manual-line-payee\s*\{/m.test(styleCss) === false);

console.log('\n' + '-'.repeat(50));
console.log(`Passed: ${passed}, Failed: ${failed}`);
if (failed > 0) {
    console.log('SOME TESTS FAILED');
    process.exit(1);
}
console.log('ALL TESTS PASSED');
