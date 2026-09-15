<?php
// design:clean -- docs/design/rules.md §9 ("สลิป / รายละเอียดการคำนวณ"), Round 3 item 3c-2.
/**
 * Payslip view -- 2-column payslip layout (Income | Deductions) + a bottom "ยอดจ่ายสุทธิ" (Net Pay)
 * summary. PURE LAYOUT ONLY: every row is passed in as already-rendered <tr> HTML -- this partial has
 * no idea what a "formula popover" or a "transfer badge" is, and must never gain one. That per-line
 * rendering (code chips, formula-explanation popovers, exempted/payee notes) is page-specific
 * business logic and stays in public/js/payroll/detail.js's own breakdownLineRowsRd()/
 * statutoryRowsRd() -- this partial (and its JS twin, payslipViewHtml() in app.js) is reused as-is
 * for the run-detail modal AND (later) the print/PDF payslip page, so it must not depend on the
 * modal it currently happens to render inside, or on any payroll-calculation-specific concept.
 *
 * 2026-09-14, Round 3 item 3c-2 follow-up (explicit instruction): the Deductions column now merges
 * BOTH statutory and item/manual deduction rows into ONE list -- a small subheader ("ภาครัฐ"/
 * "รายการเพิ่มเติม") separates the 2 groups, but ONLY when both are actually present (a group with no
 * rows contributes no subheader and no "-" placeholder of its own -- deciding which groups to show
 * headers for is still pure PRESENTATION, not a business decision about the data itself). The
 * Deductions column as a WHOLE still always renders (never hidden), same as Income -- if genuinely
 * neither group has any rows, the whole column falls back to one shared "-" placeholder row instead.
 *
 * @var string $earning_rows_html Pre-rendered <tr> rows, Income column. Never pass an empty string
 *   -- the caller renders a single "-" placeholder row itself when there is genuinely no data (see
 *   payslipViewHtml()'s JS twin), because this column is always shown, never hidden.
 * @var string $deduction_statutory_rows_html Optional (empty/null = no statutory rows at all).
 * @var string $deduction_item_rows_html Optional (empty/null = no item/manual deduction rows at all).
 * @var float|int|string $gross_amount Income column's own end-of-column total.
 * @var float|int|string $total_deduction_amount Deductions column's own end-of-column total --
 *   combined items + statutory (matches the table's own "table_deduction_amount" label elsewhere in
 *   the app -- same figure).
 * @var float|int|string $net_amount
 *
 * 2026-09-14, 2nd same-day follow-up, explicit instruction: "ตรึง tfoot ยอดรวมของทั้ง 2 คอลัมน์ไว้
 * บรรทัดเดียวกันด้านล่างสุด" -- each column's own total is a plain sibling `.payslip-col-total` div
 * now (was a `<tfoot>` row inside the same `<table>` as the line rows), pinned to the bottom via CSS
 * (style.css -- `.payslip-col` is a flex column, `.payslip-col-total` gets `margin-top: auto`) so
 * both totals land on the same bottom line regardless of which column has more rows.
 */
$pvEarningRows = $earning_rows_html ?? '';
$pvStatutoryRows = trim($deduction_statutory_rows_html ?? '');
$pvItemRows = trim($deduction_item_rows_html ?? '');
$pvGross = $gross_amount ?? 0;
$pvTotalDeduction = $total_deduction_amount ?? 0;
$pvNet = $net_amount ?? 0;

// Static English fallback text baked in server-side + data-i18n for the client sweep to translate --
// same convention every other label in this partial already uses (this app has no generic PHP-side
// lang-key lookup helper; getLangValue()/langData are JS-only, see app.js).
$pvShowGroupLabels = ($pvStatutoryRows !== '') && ($pvItemRows !== '');
$pvDeductionBody = '';
if ($pvStatutoryRows !== '') {
    if ($pvShowGroupLabels) {
        $pvDeductionBody .= '<tr class="payslip-subgroup-row"><td colspan="2" class="payslip-subgroup-label" data-i18n="payslip_group_statutory">Statutory</td></tr>';
    }
    $pvDeductionBody .= $pvStatutoryRows;
}
if ($pvItemRows !== '') {
    if ($pvShowGroupLabels) {
        $pvDeductionBody .= '<tr class="payslip-subgroup-row"><td colspan="2" class="payslip-subgroup-label" data-i18n="payslip_group_items">Items</td></tr>';
    }
    $pvDeductionBody .= $pvItemRows;
}
if ($pvDeductionBody === '') {
    $pvDeductionBody = '<tr class="payslip-row"><td colspan="2" class="text-center text-muted small">-</td></tr>';
}
?>
<div class="payslip-view">
    <div class="payslip-columns">
        <div class="payslip-col">
            <div class="payslip-col-title" data-i18n="breakdown_earnings">Income</div>
            <table class="table table-sm payslip-line-table mb-0">
                <tbody><?=$pvEarningRows?></tbody>
            </table>
            <div class="payslip-col-total">
                <span data-i18n="payslip_total_earnings">Total Income</span>
                <span class="num money-gross"><?=fmtMoney($pvGross)?></span>
            </div>
        </div>
        <div class="payslip-col">
            <div class="payslip-col-title" data-i18n="payslip_deductions_title">Deductions</div>
            <table class="table table-sm payslip-line-table mb-0<?=$pvShowGroupLabels ? ' payslip-line-table-grouped' : ''?>">
                <tbody><?=$pvDeductionBody?></tbody>
            </table>
            <div class="payslip-col-total">
                <span data-i18n="payslip_total_deductions">Total Deductions</span>
                <span class="num money-deduction"><?=fmtMoney($pvTotalDeduction)?></span>
            </div>
        </div>
    </div>
    <div class="payslip-summary">
        <div class="payslip-summary-row payslip-summary-row-net">
            <span class="payslip-summary-label" data-i18n="table_net_pay">Net Pay</span>
            <span class="num money-net fs-5"><?=fmtMoney($pvNet)?></span>
        </div>
    </div>
</div>
