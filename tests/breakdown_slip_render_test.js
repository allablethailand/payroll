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
    breakdown_add_line_unavailable: 'เพิ่มรายการ — ยังใช้ไม่ได้'
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
    extractFunctionSource(detailSource, 'breakdownLineRowsRd', 'detail.js'),
    extractFunctionSource(detailSource, 'statutoryRowsRd', 'detail.js'),
    extractFunctionSource(detailSource, 'payslipEmptyRowRd', 'detail.js'),
    extractFunctionSource(detailSource, 'breakdownViewSlipHtml', 'detail.js'),
    extractFunctionSource(detailSource, 'breakdownAddLineButtonHtml', 'detail.js'),
    'module.exports = { breakdownViewSlipHtml, breakdownAddLineButtonHtml, payslipViewHtml, payslipNetSummaryHtml, getCalls: () => calls, resetCalls };'
].join('\n');

const Module = require('module');
const m = new Module(detailJsPath);
m._compile(extracted, detailJsPath);
const { breakdownViewSlipHtml, breakdownAddLineButtonHtml, payslipViewHtml, payslipNetSummaryHtml, getCalls, resetCalls } = m.exports;

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
        { source: 'manual_line', manual_line_id: 147, code: 'UNIFORM_DEDUCT', name_th: 'หักค่าเครื่องแบบ', name_en: 'Uniform Deduction', amount: 550, note: null, is_custom: false, is_other: false, payee_type: 'company', bank_account_id: 4 }
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
                <div class="small text-muted"><i class="fa-solid fa-building me-1"></i>Retained by company</div></td>
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

const addBtn = breakdownAddLineButtonHtml();
check('it is the shared 32px round action (.btn-icon, §7), not a size of its own', addBtn.indexOf('class="btn-icon breakdown-add-line-btn"') !== -1);
check('it is disabled in this chunk, with a tooltip saying so', /disabled title="เพิ่มรายการ — ยังใช้ไม่ได้"/.test(addBtn));
check('its label comes from langData, never a hardcoded string', addBtn.indexOf('เพิ่มรายการ') !== -1);

console.log('\n' + '-'.repeat(50));
console.log(`Passed: ${passed}, Failed: ${failed}`);
if (failed > 0) {
    console.log('SOME TESTS FAILED');
    process.exit(1);
}
console.log('ALL TESTS PASSED');
