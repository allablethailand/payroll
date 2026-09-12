/* Annual Income Summary (2026-08-29) -- see AnnualIncomeSummaryModel's own docblock for the
 * fiscal-year/aggregation design this page renders.
 *
 * 2026-08-29 same-day follow-up, explicit: "สามารถดึงพนักงานทั้งหมดเลยได้ไหมครับ ไม่ต้องมีปุ่ม seach เลือก
 * filter แล้ว Reload เลย และตารางให้เป็น datatable" -- (1) the model now lists every matching employee,
 * not just ones with payroll data this fiscal year (see AnnualIncomeSummaryModel's own comment on
 * that change); (2) no Apply button -- every filter reloads immediately on change; (3) the table
 * is now a real DataTable using the FixedColumns extension (added as an npm dependency, see
 * style.css's own comment) for the frozen employee/annual-total columns instead of hand-rolled
 * CSS position:sticky. Client-side (not serverSide:true) and un-paginated on purpose -- the
 * column set (12 months, dynamic per fiscal year) and the need for footer totals to reflect every
 * filtered employee at once (computed server-side in one round trip, in `data.totals`, not
 * recomputed from on-screen rows) both fit that shape better than the server-side pattern this
 * app otherwise defaults to for a genuinely unbounded list. Rebuilt (destroy + reinit) on every
 * filter/year change since the header/column set itself can change (month state colors depend on
 * "today" relative to the selected year; the column count doesn't change, but the styling per
 * header cell does), not just the row data. */
let aisTable = null;
// 2026-09-12, Batch 5 item 6 -- module-level so this SURVIVES a year/filter re-render (explicit
// instruction: the display-toggle state must not reset on reload) -- aisRenderTable() only ever
// READS these, never resets them. "All" (#aisShowAll) has no state of its own; it's always DERIVED
// from these 2 (see the checkbox handlers further down), so it can never drift out of sync.
let aisShowIncome = true;
let aisShowDeduction = true;
// 2026-09-12, Batch 5 item 6 -- the currently-loaded table's own row/month data, kept so the Annual
// Total click-through modal (aisRenderAnnualDetail() below) can read a row's full Jan-Dec breakdown
// straight from what's ALREADY in memory -- no new endpoint needed (AnnualIncomeSummaryModel::
// summary() already returns every month's gross/deduction/net per employee in one round trip).
let aisCurrentEmployeesById = {};
let aisCurrentMonths = [];

function aisFmt(n) {
    const v = Number(n) || 0;
    return v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function aisMonthLabel(m) {
    const monthKey = 'month_' + m.month;
    const name = langData[monthKey] || m.month;
    return `${name} ${m.year}`;
}
function aisMoneyCellHtml(cell) {
    if (!cell || (!cell.gross && !cell.deduction && !cell.net)) {
        return '<span class="text-muted">-</span>';
    }
    return `<span class="ais-cell-sub">+${aisFmt(cell.gross)}</span>
        <span class="ais-cell-sub ais-cell-deduction">-${aisFmt(cell.deduction)}</span>
        <span class="ais-cell-net">${aisFmt(cell.net)}</span>`;
}
// 2026-08-30, explicit request: "ในแต่ละช่องถ้ามีข้อมูลให้สามารถกดดู Detail ได้ด้วยครับ" -- a per-employee
// month cell with real data becomes a clickable button opening #aisCellDetailModal; a cell with
// nothing in it (the existing '-' state) stays plain text, nothing to drill into. Kept separate
// from aisMoneyCellHtml() above -- that one is ALSO used for the footer's own company-wide totals
// row, which has no single employee/run to drill into and must never be clickable.
function aisEmployeeMonthCellHtml(row, cell, month) {
    if (!cell || (!cell.gross && !cell.deduction && !cell.net)) {
        return '<span class="text-muted">-</span>';
    }
    return `<button type="button" class="ais-cell-clickable" data-employee-id="${row.employee_id}" data-year="${month.year}" data-month="${month.month}">
        <span class="ais-cell-sub">+${aisFmt(cell.gross)}</span>
        <span class="ais-cell-sub ais-cell-deduction">-${aisFmt(cell.deduction)}</span>
        <span class="ais-cell-net">${aisFmt(cell.net)}</span>
    </button>`;
}
// 2026-09-12, Batch 5 item 6 -- the "Annual Total" column becomes a click-through to that row's own
// full Jan-Dec breakdown (#aisAnnualDetailModal, aisRenderAnnualDetail() below) -- reads straight
// from the SAME row object already in memory (aisCurrentEmployeesById), no new endpoint. Every
// employee has SOME annual figures (even an all-zero one, per AnnualIncomeSummaryModel's own "list
// every matching employee" design), so unlike aisEmployeeMonthCellHtml() above this is always
// clickable, never plain text.
function aisAnnualTotalCellHtml(row) {
    // Deliberately only ais-annual-total-clickable, NOT .ais-cell-clickable -- style.css's own
    // button-reset/hover rule lists both class names in its selector so this still gets the exact
    // same look with no separate CSS block, but this element must NOT literally carry the
    // .ais-cell-clickable class itself: that class is also the OTHER delegated click handler's
    // selector (which expects data-year/data-month, neither of which this button has), and jQuery
    // delegation would fire both handlers on the same click if this button matched both.
    return `<button type="button" class="ais-annual-total-clickable" data-employee-id="${row.employee_id}">
        <span class="ais-cell-sub">+${aisFmt(row.annual_gross)}</span>
        <span class="ais-cell-sub ais-cell-deduction">-${aisFmt(row.annual_deduction)}</span>
        <span class="ais-total-value">${aisFmt(row.annual_net)}</span>
    </button>`;
}

function aisCurrentFilters() {
    return {
        fiscal_year: $('#aisFiscalYear').val(),
        cycle_id: $('#aisFilterCycle').val() || '',
        department_id: $('#aisFilterDepartment').val() || '',
        team_id: $('#aisFilterTeam').val() || '',
        branch_id: $('#aisFilterBranch').val() || '',
        role_id: $('#aisFilterRole').val() || '',
        employee_status: $('#aisFilterStatus').val() || '',
    };
}
function aisUpdateClearFilterVisibility() {
    const f = aisCurrentFilters();
    const hasFilter = !!(f.cycle_id || f.department_id || f.team_id || f.branch_id || f.role_id || f.employee_status);
    $('#aisFilterClearRow').toggleClass('d-none', !hasFilter);
}

function loadAisFiscalYears() {
    $.ajax({
        url: `${BASE_URL}/api/annual-income-summary.years`,
        method: 'GET',
        dataType: 'json',
        success: function (res) {
            if (!res.status) return;
            const $select = $('#aisFiscalYear').empty();
            const years = res.data || [];
            if (!years.length) {
                const currentYear = new Date().getFullYear();
                years.push(currentYear);
            }
            years.forEach(function (y) {
                $select.append(new Option('FY ' + y, y));
            });
            loadAisSummary();
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while loading the data.');
        }
    });
}

function loadAisSummary() {
    const filters = aisCurrentFilters();
    if (!filters.fiscal_year) return;
    $('.ais-table-wrap').addClass('d-none');
    $('#aisTableEmpty').addClass('d-none');
    $.ajax({
        url: `${BASE_URL}/api/annual-income-summary.summary`,
        method: 'GET',
        dataType: 'json',
        data: filters,
        success: function (res) {
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred while loading the data.');
                return;
            }
            aisRenderSummaryCards(res.data.totals);
            aisRenderTable(res.data);
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while loading the data.');
        }
    });
}

function aisRenderSummaryCards(totals) {
    $('#aisSummaryEmployeeCount').text(totals.employee_count || 0);
    $('#aisSummaryGross').text(aisFmt(totals.annual_gross));
    $('#aisSummaryDeduction').text(aisFmt(totals.annual_deduction));
    $('#aisSummaryNet').text(aisFmt(totals.annual_net));
}

function aisRenderTable(data) {
    const months = data.months || [];
    const employees = data.employees || [];
    // 2026-09-12, Batch 5 item 6 -- kept for the Annual Total click-through modal, see this
    // variable's own top-of-file docblock. Updated on every render (year/filter change) so the
    // modal always reads the CURRENTLY-shown data, not a stale snapshot from an earlier load.
    aisCurrentMonths = months;
    aisCurrentEmployeesById = {};
    employees.forEach(function (e) { aisCurrentEmployeesById[e.employee_id] = e; });

    if (aisTable) {
        aisTable.destroy();
        aisTable = null;
        $('#tb_annual_summary').empty().append('<thead></thead><tfoot></tfoot>');
    }

    if (!employees.length) {
        $('#aisTableEmpty').removeClass('d-none');
        $('.ais-table-wrap').addClass('d-none');
        return;
    }
    $('.ais-table-wrap').removeClass('d-none');

    // ---- head (built directly, before DataTable init -- column count/labels are dynamic per
    // fiscal year, so this isn't the usual "static thead in the view" DataTables setup) ----
    // 2026-09-08, explicit request: "แยก code กับ ชื่อพนักงานเป็นคนละ column กันครับ แผนก ทีม ตำแหน่ง
    // ไม่ต้อง fixed column ครับ ให้เลื่อนได้เหมือนเดือน" -- Employee No./Employee (name) split into 2 real
    // columns (matches every other table in this app's own "code and name are separate columns"
    // convention, e.g. Employee Recheck Data) and are the only 2 columns still frozen left (see
    // `left: 2` on the DataTable init below, down from 4) -- Department/Team/Position moved OUT of
    // the frozen group entirely, scrolling together with the month columns instead.
    let headHtml = '<tr><th>' + (langData['employee_no'] || 'Employee No.') + '</th>'
        + '<th>' + (langData['employee'] || 'Employee') + '</th>'
        + '<th>' + (langData['department'] || 'Department') + '</th>'
        + '<th>' + (langData['team'] || 'Team') + '</th>'
        + '<th>' + (langData['position'] || 'Position') + '</th>';
    months.forEach(function (m) {
        headHtml += `<th class="ais-month-${m.state}">${escapeHtml(aisMonthLabel(m))}</th>`;
    });
    headHtml += '<th>' + (langData['annual_total'] || 'Annual Total') + '</th></tr>';
    $('#tb_annual_summary thead').html(headHtml);

    // ---- foot (real totals from the server -- reflects every filtered employee, not just what
    // DataTable's own client-side search box currently shows) ----
    // Plain empty <td>s (one per identity column: Employee No./Employee/Department/Team/Position) --
    // keeping the footer's own cell count identical to the header's (rather than collapsing these
    // into the "Total" label's own colspan) is what keeps each column's <td> lined up under its own
    // <th> in a plain (non-scrollX) table.
    let footHtml = '<tr><td>' + (langData['total'] || 'Total') + '</td><td></td><td></td><td></td><td></td>';
    months.forEach(function (m) {
        const mt = data.totals.months[m.key] || { gross: 0, deduction: 0, net: 0 };
        footHtml += `<td class="text-end">${aisMoneyCellHtml(mt)}</td>`;
    });
    footHtml += `<td class="text-end">
        <span class="ais-cell-sub">+${aisFmt(data.totals.annual_gross)}</span>
        <span class="ais-cell-sub ais-cell-deduction">-${aisFmt(data.totals.annual_deduction)}</span>
        <span class="ais-total-value">${aisFmt(data.totals.annual_net)}</span>
    </td></tr>`;
    $('#tb_annual_summary tfoot').html(footHtml);

    // ---- columns ----
    // 2026-09-12, Batch 5 item 5 step 2 -- Employee column now shows the SAME avatar+name treatment
    // Process Detail's own "Updated By" column already uses (apvPersonLineHtml(), app.js -- no
    // second avatar function). Object-form render (not plain render:) since embedding the avatar's
    // <img>/initial-span markup directly into `display` would otherwise make DataTables sort/search
    // against that raw HTML string instead of the employee's own name.
    const columns = [
        { data: null, render: (row) => `<span class="ais-employee-no">${escapeHtml(row.employee_no)}</span>` },
        {
            data: null,
            render: {
                display: (row) => apvPersonLineHtml((currentLang === 'th' ? row.name_th : row.name_en) || row.name_th || row.name_en || '', 32, row.profile_photo_path, row.employee_id ? { employeeId: row.employee_id } : null),
                sort: (row) => (currentLang === 'th' ? row.name_th : row.name_en) || row.name_th || row.name_en || '',
                filter: (row) => (currentLang === 'th' ? row.name_th : row.name_en) || row.name_th || row.name_en || '',
            }
        },
        { data: null, render: (row) => escapeHtml((currentLang === 'th' ? row.department_name_th : row.department_name_en) || row.department_name_th || '-') },
        { data: null, render: (row) => escapeHtml((currentLang === 'th' ? row.team_name_th : row.team_name_en) || row.team_name_th || '-') },
        { data: null, render: (row) => escapeHtml((currentLang === 'th' ? row.position_name_th : row.position_name_en) || row.position_name_th || '-') },
    ];
    months.forEach(function (m, idx) {
        // Object-form render (display/sort/filter split, same DataTables sort-safety convention
        // this app already uses for formatted date columns) -- sorting/filtering a money column by
        // its comma-formatted display string would sort lexicographically instead of numerically.
        columns.push({
            data: null,
            className: 'text-end',
            render: {
                display: (row) => aisEmployeeMonthCellHtml(row, row.months[idx], m),
                sort: (row) => row.months[idx] ? row.months[idx].net : 0,
                filter: (row) => row.months[idx] ? row.months[idx].net : 0,
            }
        });
    });
    columns.push({
        data: null,
        className: 'text-end',
        render: {
            display: (row) => aisAnnualTotalCellHtml(row),
            sort: (row) => row.annual_net,
            filter: (row) => row.annual_net,
        }
    });

    // 2026-09-12, Batch 5 item 5 step 1 (step 3/4 follow-up) -- routed through the shared
    // initSharedDataTable() helper (app.js, Batch 3C item 6) for the common bits (language/
    // pageLength/lengthMenu/ordering defaults, row-count-based `searching`) while every option this
    // table's OWN shape genuinely needs (data/columns, paging:false, info:false, order:[],
    // drawCallback/initComplete) is passed as an explicit override -- the helper itself gained ONE
    // fix (step 3/4: prefer `options.dtOptions.data.length` -- the SAME array DataTables itself
    // ends up using, no separate/duplicate `data` needed here -- over an always-empty-at-that-point
    // tbody count) rather than forcing `searching` true here, so this table's own search-box
    // visibility now follows the SAME row-count threshold every other table using this helper
    // already does.
    aisTable = initSharedDataTable('#tb_annual_summary', {
        dtOptions: {
            data: employees,
            columns: columns,
            paging: false,
            info: false,
            order: [],
            // 2026-09-08, explicit follow-up request ("column ทั้ง 3 Tab พนักงาน fixed และ column รวมทั้งปี
            // fixed ขวา ส่วนของเดือนใช้เมาส์ลากดูได้เหมือนหน้า employee tab ตรวจสอบข้อมูล") -- was DataTables'
            // own core `scrollX`+`scrollY`+the FixedColumns extension (`fixedColumns: {left:4, right:1}`),
            // confirmed BROKEN app-wide for 2 independent reasons (see public/js/sticky-table-columns.js's
            // own docblock): FixedColumns itself throws on load (missing `DataTable.Dom` in the installed
            // `datatables.net` core), and `scrollX`/`scrollY` need CSS this app never actually loads
            // (the base `datatables.net` skin's own stylesheet, only its bs5 skin was ever installed) --
            // so neither the frozen columns nor the vertical 60vh cap were ever actually working, despite
            // being configured. Rebuilt on the SAME plain-CSS-position:sticky pattern Employee Recheck
            // Data already uses -- `initStickyColumns()` freezes columns on the left/right,
            // `initTableDragScroll()` wraps the table in `.table-responsive` and adds real click-and-drag
            // panning for the columns in between. The old `scrollY:'60vh'` vertical cap is NOT replaced --
            // it was never actually capping anything either (same missing-CSS reason), so dropping it is
            // not a real behavior change.
            // 2026-09-08, same-day follow-up ("แยก code กับ ชื่อพนักงานเป็นคนละ column กันครับ แผนก ทีม ตำแหน่ง
            // ไม่ต้อง fixed column ครับ ให้เลื่อนได้เหมือนเดือน") -- left dropped from 4 to 2 (Employee No.+
            // Employee only, now that they're 2 real columns instead of 1 combined one -- see the head/
            // columns above) -- Department/Team/Position are no longer part of the frozen group at all,
            // they scroll together with the month columns now.
            drawCallback: function () { initStickyColumns('#tb_annual_summary', { left: 2, right: 1 }); },
            // 2026-09-04, Backlog Phase 11, T067 -- Department/Team/Position are genuinely categorical
            // (a small, real distinct-value set), the confirmed real gap in this table. Employee (name+
            // no, effectively unique per row) and the 12 month/annual-total money columns are
            // deliberately NOT included -- they already sort/filter correctly via their own object-form
            // {display,sort,filter} render (CLAUDE.md's own formatted-column convention, already
            // correct here), but a discrete Excel-style checkbox list of every distinct MONEY amount
            // across all employees has no real user value the way it does for a handful of department
            // names -- same "widget/no-single-filterable-value" exemption spirit CLAUDE.md's own Table
            // convention already carves out elsewhere (mini-timeline/progress-bar/avatar columns), even
            // though a money column isn't literally named in that list. Re-applied on every rebuild
            // (destroy:true + initComplete, not a one-time init) since this table's own column set/data
            // changes on every filter/year change -- initComplete fires again each time.
            // 2026-09-08: indices shifted 1,2,3 -> 2,3,4 now that Employee No./Employee are 2 separate
            // columns instead of 1.
            initComplete: function () {
                initExcelColumnFilters(this.api(), {
                    mode: 'client',
                    columns: [
                        { index: 2, key: 'department' },
                        { index: 3, key: 'team' },
                        { index: 4, key: 'position' },
                    ],
                });
                // Re-run AFTER initExcelColumnFilters rebuilds the header cells' own inner markup (sort
                // arrow + filter icon), which can nudge their rendered width slightly -- drawCallback's
                // own call above (which fires BEFORE initComplete on the very first draw) would otherwise
                // compute the left offsets from marginally-stale widths.
                initStickyColumns('#tb_annual_summary', { left: 2, right: 1 });
                initTableDragScroll('#tb_annual_summary');
            },
        },
    });
    updateText($('#tb_annual_summary')[0]);
    // 2026-09-12, Batch 5 item 6 -- re-applied on EVERY render (not just once) so the display-toggle
    // state survives a year/filter change exactly as instructed: the checkboxes/module state above
    // are never reset here, only re-synced onto whatever fresh <table> this render just built.
    applyAisColumnDisplayToggle();
}

// 2026-08-30, explicit request: "ในแต่ละช่องถ้ามีข้อมูลให้สามารถกดดู Detail ได้ด้วยครับ" -- opens
// #aisCellDetailModal with that one employee+month's own line-item breakdown
// (AnnualIncomeSummaryModel::cellDetail()). More than one run can land in the same calendar month
// (e.g. a regular run plus an off-cycle incentive run) -- renders one card per run returned,
// rather than assuming there's always exactly one.
function aisDetailLineRowsHtml(lines, amountKey) {
    if (!lines || !lines.length) return `<div class="text-muted small">-</div>`;
    return lines.map(function (l) {
        const name = (currentLang === 'th' ? l.name_th : l.name_en) || l.name_th || l.name_en || l.code || '-';
        const amount = l[amountKey] !== undefined ? l[amountKey] : l.amount;
        return `<div class="d-flex justify-content-between small py-1 border-bottom">
            <span>${escapeHtml(name)}</span>
            <span class="fw-semibold">${aisFmt(amount)}</span>
        </div>`;
    }).join('');
}
function aisRenderCellDetail(runs) {
    if (!runs || !runs.length) {
        $('#aisCellDetailBody').html(`<div class="text-center text-muted py-4">${langData['ais_no_data'] || 'No payroll data found for this fiscal year.'}</div>`);
        return;
    }
    const html = runs.map(function (run) {
        const period = `${formatDisplayDate ? formatDisplayDate(run.period_start_date) : run.period_start_date} - ${formatDisplayDate ? formatDisplayDate(run.period_end_date) : run.period_end_date}`;
        // 2026-09-10: this month's grid cell is now keyed by payment_date, not period_start_date --
        // showing the payment date alongside the (still-displayed) pay period makes it clear WHY this
        // run appears under this particular month even when its period spans a month boundary.
        const paymentLabel = run.payment_date ? (formatDisplayDate ? formatDisplayDate(run.payment_date) : run.payment_date) : '-';
        return `<div class="card-surface p-3 mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="fw-bold">${escapeHtml(run.run_name || '-')}</div>
                <div class="text-end">
                    <div class="text-muted small">${escapeHtml(period)}</div>
                    <div class="text-muted small">${escapeHtml(langData['modal_payment_date'] || 'Payment Date')}: ${escapeHtml(paymentLabel)}</div>
                </div>
            </div>
            <div class="d-flex justify-content-between small py-1 border-bottom">
                <span>${langData['table_base_salary'] || 'Base Salary'}</span>
                <span class="fw-semibold">${aisFmt(run.base_salary_amount)}</span>
            </div>
            <div class="fw-semibold small text-success mt-2 mb-1">${langData['breakdown_earnings'] || 'Income'}</div>
            ${aisDetailLineRowsHtml(run.earning_lines, 'amount')}
            <div class="fw-semibold small text-danger mt-2 mb-1">${langData['table_deduction_amount'] || 'Deductions'}</div>
            ${aisDetailLineRowsHtml(run.deduction_lines, 'amount')}
            <div class="fw-semibold small text-warning-emphasis mt-2 mb-1">${langData['statutory_items'] || 'Statutory'}</div>
            ${aisDetailLineRowsHtml(run.statutory_lines, 'employee_amount')}
            <div class="d-flex justify-content-between mt-3 pt-2 border-top">
                <span class="fw-bold">${langData['table_net_pay'] || 'Net Pay'}</span>
                <span class="fw-bold text-brand">${aisFmt(run.net_amount)}</span>
            </div>
        </div>`;
    }).join('');
    $('#aisCellDetailBody').html(html);
}
$(document).on('click', '.ais-cell-clickable', function () {
    const employeeId = $(this).data('employee-id');
    const year = $(this).data('year');
    const month = $(this).data('month');
    $('#aisCellDetailModalTitle').text(aisMonthLabel({ year: year, month: month }));
    $('#aisCellDetailBody').html(`<div class="text-center text-secondary py-4"><i class="fa-solid fa-spinner fa-spin me-1"></i>${langData['loading'] || 'Loading...'}</div>`);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('aisCellDetailModal')).show();
    // 2026-09-12, Batch 5 item 6 follow-up: real gap found -- this call never forwarded cycle_id,
    // even though item 5 already added backend cycle-filter support to cellDetail(). One delegated
    // handler on `document` serves BOTH the main table's month cells AND (this round) the month
    // rows inside #aisAnnualDetailModal -- reading the CURRENT filter state at click time here
    // covers both call sites with this one fix, no per-caller wiring needed.
    $.getJSON(`${BASE_URL}/api/annual-income-summary.cell-detail`, { employee_id: employeeId, year: year, month: month, cycle_id: aisCurrentFilters().cycle_id || '' }, function (res) {
        aisRenderCellDetail(res.status ? res.data : []);
    });
});

/* ==================== Batch 5 item 6: income/deduction display toggle + Annual Total
   click-through modal (Tab 1, Annual Income Summary, only -- Tab 2/3 have no gross/deduction
   breakdown to toggle, and their own "Annual Total" column stays non-clickable this round; Tab 4
   has no month matrix at all). ==================== */
// 2026-09-12, Batch 5 item 6 -- pure CSS class toggle on the table itself, no re-render/reload:
// .ais-cell-sub (income) and .ais-cell-sub.ais-cell-deduction (deduction) are the SAME 2 marker
// classes month cells, the Annual Total column (aisAnnualTotalCellHtml() above), AND the footer's
// own grand-total cell (aisMoneyCellHtml() above, used for both the per-month footer cells and the
// grand-total cell) all already share -- one class toggle here covers all 3 places at once,
// automatically consistent (explicit instruction: filter the Annual Total column and footer too,
// not just month cells), with nothing to keep back in sync by hand. .ais-cell-net (the plain-net
// month cells) is untouched -- net is always shown regardless of this toggle.
function applyAisColumnDisplayToggle() {
    $('#tb_annual_summary').toggleClass('ais-hide-income', !aisShowIncome).toggleClass('ais-hide-deduction', !aisShowDeduction);
}
// "All" (#aisShowAll) has no state of its own -- always DERIVED from aisShowIncome/aisShowDeduction
// so it can never drift out of sync with them.
function aisSyncColumnToggleCheckboxes() {
    $('#aisShowIncome').prop('checked', aisShowIncome);
    $('#aisShowDeduction').prop('checked', aisShowDeduction);
    $('#aisShowAll').prop('checked', aisShowIncome && aisShowDeduction);
}
// Clicking "All" is only ever an "enable everything" action -- explicit instruction: it must never
// be a way to hide everything (the Income/Deduction handlers below already guard against that on
// their own, so this needs no such guard, but staying consistent with "All can't mean nothing").
$(document).on('change', '#aisShowAll', function () {
    aisShowIncome = true;
    aisShowDeduction = true;
    aisSyncColumnToggleCheckboxes();
    applyAisColumnDisplayToggle();
});
$(document).on('change', '#aisShowIncome', function () {
    aisShowIncome = $(this).is(':checked');
    // Explicit instruction: unchecking the last one of the two auto-recovers to both checked,
    // rather than ever leaving the table showing nothing.
    if (!aisShowIncome && !aisShowDeduction) {
        aisShowIncome = true;
        aisShowDeduction = true;
    }
    aisSyncColumnToggleCheckboxes();
    applyAisColumnDisplayToggle();
});
$(document).on('change', '#aisShowDeduction', function () {
    aisShowDeduction = $(this).is(':checked');
    if (!aisShowIncome && !aisShowDeduction) {
        aisShowIncome = true;
        aisShowDeduction = true;
    }
    aisSyncColumnToggleCheckboxes();
    applyAisColumnDisplayToggle();
});

// 2026-09-12, Batch 5 item 6 -- "has real data" uses the EXACT same all-zero test
// aisMoneyCellHtml()/aisEmployeeMonthCellHtml() already use to decide whether a month cell shows
// real figures or a plain '-' -- same definition of "no data this month" throughout this page, not
// a second one invented for this stat.
function aisMonthHasData(cell) {
    return !!(cell && (cell.gross || cell.deduction || cell.net));
}
// Explicit instruction: average = annual_net / (months WITH data only, not a flat /12) so a
// mid-year hire's average reflects their real pay, not diluted by months before they even joined;
// highest month is by net, ties go to the FIRST such month (strict `>` while iterating in
// chronological order naturally does this -- a later equal value is never `>` the one already
// found).
function aisAnnualDetailStatsForRow(row) {
    const months = row.months || [];
    let monthsWithData = 0;
    let peakIdx = -1;
    let peakNet = -Infinity;
    months.forEach(function (cell, idx) {
        if (aisMonthHasData(cell)) {
            monthsWithData++;
        }
        const net = cell ? (Number(cell.net) || 0) : 0;
        if (net > peakNet) {
            peakNet = net;
            peakIdx = idx;
        }
    });
    return {
        monthsWithData: monthsWithData,
        average: monthsWithData > 0 ? (row.annual_net / monthsWithData) : 0,
        peakIdx: peakIdx,
    };
}
// 2026-09-12, Batch 5 item 6 -- plain <table>, not a DataTable (explicit instruction: a fixed
// 12-row list needs no pagination/search/sort of its own). Reads straight from the row object
// already in memory (aisCurrentEmployeesById) + aisCurrentMonths for month labels/state -- no
// endpoint call at all (see this task's own step-1 report on why one isn't needed). A month with no
// data shows '-' (explicit instruction), not "0.00" -- matches aisMoneyCellHtml()'s own convention
// for the main table's month cells. Modal always shows all 3 columns regardless of the Income/
// Deduction checkboxes above (explicit instruction) -- this function never reads
// aisShowIncome/aisShowDeduction at all. Each month row is still `.ais-cell-clickable` (the SAME
// existing delegated handler/#aisCellDetailModal as the main table's own month cells) so drilling
// into one specific month's real line items works identically from inside this modal -- Bootstrap 5
// stacks the 2 modals natively, no extra wiring needed.
function aisRenderAnnualDetail(row) {
    const months = aisCurrentMonths || [];
    const stats = aisAnnualDetailStatsForRow(row);
    const name = (currentLang === 'th' ? row.name_th : row.name_en) || row.name_th || row.name_en || '';
    const deptName = (currentLang === 'th' ? row.department_name_th : row.department_name_en) || row.department_name_th || '-';
    const fiscalYearLabel = months.length ? months[0].year + (months[0].year !== months[months.length - 1].year ? '-' + months[months.length - 1].year : '') : '';

    $('#aisAnnualDetailModalTitle').html(`${apvPersonLineHtml(name, 32, row.profile_photo_path, row.employee_id ? { employeeId: row.employee_id } : null)}
        <span class="text-muted small ms-2">${escapeHtml(row.employee_no || '')} &middot; ${escapeHtml(deptName)}</span>`);

    const rowsHtml = months.map(function (m, idx) {
        const cell = row.months[idx];
        const hasData = aisMonthHasData(cell);
        const cls = idx === stats.peakIdx && hasData ? ' class="table-warning"' : '';
        const clickableAttrs = hasData ? ` data-employee-id="${row.employee_id}" data-year="${m.year}" data-month="${m.month}"` : '';
        const rowTag = hasData ? `<tr class="ais-cell-clickable"${clickableAttrs} style="cursor:pointer;"${cls}>` : `<tr${cls}>`;
        return `${rowTag}
            <td>${escapeHtml(aisMonthLabel(m))}</td>
            <td class="text-end">${hasData ? aisFmt(cell.gross) : '-'}</td>
            <td class="text-end">${hasData ? aisFmt(cell.deduction) : '-'}</td>
            <td class="text-end fw-semibold">${hasData ? aisFmt(cell.net) : '-'}</td>
        </tr>`;
    }).join('');

    $('#aisAnnualDetailBody').html(`
        <div class="row g-3 mb-4">
            <div class="col-4">
                <div class="stat-card stat-card-success h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-coins"></i></div>
                    <div>
                        <div class="stat-card-label" data-i18n="annual_total">Annual Total</div>
                        <div class="stat-card-value">${aisFmt(row.annual_net)}</div>
                    </div>
                </div>
            </div>
            <div class="col-4">
                <div class="stat-card stat-card-info h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-calculator"></i></div>
                    <div>
                        <div class="stat-card-label">${langData['ais_avg_per_month'] || 'Average per Month'} (${stats.monthsWithData} ${langData['ais_months_unit'] || 'months'})</div>
                        <div class="stat-card-value">${aisFmt(stats.average)}</div>
                    </div>
                </div>
            </div>
            <div class="col-4">
                <div class="stat-card stat-card-primary h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-trophy"></i></div>
                    <div>
                        <div class="stat-card-label">${langData['ais_highest_month'] || 'Highest Month'}</div>
                        <div class="stat-card-value">${stats.peakIdx >= 0 ? escapeHtml(aisMonthLabel(months[stats.peakIdx])) : '-'}</div>
                    </div>
                </div>
            </div>
        </div>
        <table class="table table-sm table-hover ais-table">
            <thead class="table-light text-secondary">
                <tr>
                    <th data-i18n="month">Month</th>
                    <th class="text-end" data-i18n="breakdown_earnings">Income</th>
                    <th class="text-end" data-i18n="table_deduction_amount">Deductions</th>
                    <th class="text-end" data-i18n="table_net_pay">Net Pay</th>
                </tr>
            </thead>
            <tbody>${rowsHtml}</tbody>
            <tfoot>
                <tr class="fw-bold">
                    <td data-i18n="total">Total</td>
                    <td class="text-end">${aisFmt(row.annual_gross)}</td>
                    <td class="text-end">${aisFmt(row.annual_deduction)}</td>
                    <td class="text-end">${aisFmt(row.annual_net)}</td>
                </tr>
            </tfoot>
        </table>
    `);
    updateText($('#aisAnnualDetailBody')[0]);
}
$(document).on('click', '.ais-annual-total-clickable', function () {
    const employeeId = $(this).data('employee-id');
    const row = aisCurrentEmployeesById[employeeId];
    if (!row) return;
    aisRenderAnnualDetail(row);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('aisAnnualDetailModal')).show();
});

$(document).on('click', '#aisStationFilterToggle', function () {
    const $filter = $('#aisStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('change', '#aisFiscalYear, #aisFilterCycle, #aisFilterDepartment, #aisFilterTeam, #aisFilterBranch, #aisFilterRole, #aisFilterStatus', function () {
    aisUpdateClearFilterVisibility();
    loadAisSummary();
});
$(document).on('click', '#aisClearFilterBtn', function () {
    $('#aisFilterCycle, #aisFilterDepartment, #aisFilterTeam, #aisFilterBranch, #aisFilterRole').val(null).trigger('change.select2');
    $('#aisFilterStatus').val('').trigger('change');
});

/* ==================== Tab 2: Annual Withholding Tax (PIT) Summary (Phase 4, T027) ====================
   Same shape/conventions as Tab 1 above (client-side, un-paginated, FixedColumns) -- tracking a
   single tax_withheld figure per employee per month instead of gross/deduction/net. Cell click-to-
   drill-down reuses the EXACT same #aisCellDetailModal/cellDetail() endpoint as Tab 1 -- that
   endpoint already returns the full per-run breakdown (earning/deduction/statutory lines,
   statutory including the TH_PIT line), so no separate PIT-specific detail view was needed.
   Lazy-loaded on first shown.bs.tab (this app's own standing habit for a table built while its own
   tab-pane is display:none -- see T018's own Recheck tab for the identical reasoning). ==================== */
let aisPitTable = null;
let aisPitLoaded = false;

function aisPitCurrentFilters() {
    return {
        fiscal_year: $('#aisPitFiscalYear').val(),
        cycle_id: $('#aisPitFilterCycle').val() || '',
        department_id: $('#aisPitFilterDepartment').val() || '',
        team_id: $('#aisPitFilterTeam').val() || '',
        branch_id: $('#aisPitFilterBranch').val() || '',
        role_id: $('#aisPitFilterRole').val() || '',
        employee_status: $('#aisPitFilterStatus').val() || '',
    };
}
function aisPitUpdateClearFilterVisibility() {
    const f = aisPitCurrentFilters();
    const hasFilter = !!(f.cycle_id || f.department_id || f.team_id || f.branch_id || f.role_id || f.employee_status);
    $('#aisPitFilterClearRow').toggleClass('d-none', !hasFilter);
}
function loadAisPitFiscalYears() {
    $.ajax({
        url: `${BASE_URL}/api/annual-income-summary.years`, method: 'GET', dataType: 'json',
        success: function (res) {
            if (!res.status) return;
            const $select = $('#aisPitFiscalYear').empty();
            const years = res.data && res.data.length ? res.data : [new Date().getFullYear()];
            years.forEach(y => $select.append(new Option('FY ' + y, y)));
            loadAisPitSummary();
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
}
function loadAisPitSummary() {
    const filters = aisPitCurrentFilters();
    if (!filters.fiscal_year) return;
    $('#ais-pit-pane .ais-table-wrap').addClass('d-none');
    $('#aisPitTableEmpty').addClass('d-none');
    $.ajax({
        url: `${BASE_URL}/api/annual-income-summary.pit-summary`, method: 'GET', dataType: 'json', data: filters,
        success: function (res) {
            if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred while loading the data.'); return; }
            $('#aisPitSummaryEmployeeCount').text(res.data.totals.employee_count || 0);
            $('#aisPitSummaryTotal').text(aisFmt(res.data.totals.annual_tax_withheld));
            aisRenderPitTable(res.data);
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
}
function aisPitCellHtml(row, val, month) {
    if (!val) return '<span class="text-muted">-</span>';
    return `<button type="button" class="ais-cell-clickable" data-employee-id="${row.employee_id}" data-year="${month.year}" data-month="${month.month}">
        <span class="ais-cell-net">${aisFmt(val)}</span>
    </button>`;
}
function aisRenderPitTable(data) {
    const months = data.months || [];
    const employees = data.employees || [];

    if (aisPitTable) {
        aisPitTable.destroy();
        aisPitTable = null;
        $('#tb_ais_pit').empty().append('<thead></thead><tfoot></tfoot>');
    }
    if (!employees.length) {
        $('#aisPitTableEmpty').removeClass('d-none');
        $('#ais-pit-pane .ais-table-wrap').addClass('d-none');
        return;
    }
    $('#ais-pit-pane .ais-table-wrap').removeClass('d-none');

    // 2026-09-08, explicit request: "แยก code กับ ชื่อพนักงานเป็นคนละ column กันครับ แผนก ทีม ตำแหน่ง ไม่ต้อง
    // fixed column ครับ ให้เลื่อนได้เหมือนเดือน" -- same split as Tab 1's own aisTable above.
    let headHtml = '<tr><th>' + (langData['employee_no'] || 'Employee No.') + '</th>'
        + '<th>' + (langData['employee'] || 'Employee') + '</th>'
        + '<th>' + (langData['department'] || 'Department') + '</th>'
        + '<th>' + (langData['team'] || 'Team') + '</th>'
        + '<th>' + (langData['position'] || 'Position') + '</th>';
    months.forEach(m => { headHtml += `<th class="ais-month-${m.state}">${escapeHtml(aisMonthLabel(m))}</th>`; });
    headHtml += '<th>' + (langData['annual_total'] || 'Annual Total') + '</th></tr>';
    $('#tb_ais_pit thead').html(headHtml);

    let footHtml = '<tr><td>' + (langData['total'] || 'Total') + '</td><td></td><td></td><td></td><td></td>';
    months.forEach(m => { footHtml += `<td class="text-end">${aisFmt((data.totals.months || {})[m.key] || 0)}</td>`; });
    footHtml += `<td class="text-end"><span class="ais-total-value">${aisFmt(data.totals.annual_tax_withheld)}</span></td></tr>`;
    $('#tb_ais_pit tfoot').html(footHtml);

    // 2026-09-12, Batch 5 item 5 step 2 -- same avatar+name Employee column as Tab 1's own aisTable
    // above (apvPersonLineHtml(), app.js -- no second avatar function), object-form render for the
    // same sort/search-safety reason.
    const columns = [
        { data: null, render: (row) => `<span class="ais-employee-no">${escapeHtml(row.employee_no)}</span>` },
        {
            data: null,
            render: {
                display: (row) => apvPersonLineHtml((currentLang === 'th' ? row.name_th : row.name_en) || row.name_th || row.name_en || '', 32, row.profile_photo_path, row.employee_id ? { employeeId: row.employee_id } : null),
                sort: (row) => (currentLang === 'th' ? row.name_th : row.name_en) || row.name_th || row.name_en || '',
                filter: (row) => (currentLang === 'th' ? row.name_th : row.name_en) || row.name_th || row.name_en || '',
            }
        },
        { data: null, render: (row) => escapeHtml((currentLang === 'th' ? row.department_name_th : row.department_name_en) || row.department_name_th || '-') },
        { data: null, render: (row) => escapeHtml((currentLang === 'th' ? row.team_name_th : row.team_name_en) || row.team_name_th || '-') },
        { data: null, render: (row) => escapeHtml((currentLang === 'th' ? row.position_name_th : row.position_name_en) || row.position_name_th || '-') },
    ];
    months.forEach(function (m, idx) {
        columns.push({
            data: null, className: 'text-end',
            render: { display: (row) => aisPitCellHtml(row, row.months[idx], m), sort: (row) => row.months[idx] || 0, filter: (row) => row.months[idx] || 0 }
        });
    });
    columns.push({
        data: null, className: 'text-end',
        render: { display: (row) => `<span class="ais-total-value">${aisFmt(row.annual_tax_withheld)}</span>`, sort: (row) => row.annual_tax_withheld, filter: (row) => row.annual_tax_withheld }
    });

    // 2026-09-12, Batch 5 item 5 step 1 (step 3 follow-up) -- routed through the shared
    // initSharedDataTable() helper, same reasoning as Tab 1's own aisTable above -- the helper reads
    // the row count straight from `dtOptions.data` below (the same array DataTables itself uses).
    aisPitTable = initSharedDataTable('#tb_ais_pit', {
        dtOptions: {
            data: employees, columns: columns, paging: false, info: false, order: [],
            // 2026-09-08, same fix as Tab 1's own aisTable above -- see that DataTable's own comment for
            // the full "scrollX/FixedColumns confirmed broken app-wide" reasoning, unchanged here. left:2
            // (Employee No.+Employee only, not Department/Team/Position) matches Tab 1's own same-day
            // follow-up too.
            drawCallback: function () { initStickyColumns('#tb_ais_pit', { left: 2, right: 1 }); },
            initComplete: function () { initTableDragScroll('#tb_ais_pit'); },
        },
    });
    updateText($('#tb_ais_pit')[0]);
}

/* ==================== Tab: Annual SSO Contribution Summary (Batch 2, item 6, 2026-09-10) ====================
   Direct structural mirror of the Annual Withholding Tax (PIT) Summary tab above -- same fiscal-
   year concept, same client-side/un-paginated/FixedColumns table, same #aisCellDetailModal cell
   click-to-drill-down (that modal already returns the full statutory breakdown, TH_SSO line
   included, so no SSO-specific detail view was needed here either). Tracks employee-side SSO
   contribution only (AnnualIncomeSummaryModel::rawDeductionRows()'s own `employee_amount`, confirmed via
   AskUserQuestion -- not employee+employer combined). ==================== */
let aisSsoTable = null;
let aisSsoLoaded = false;

function aisSsoCurrentFilters() {
    return {
        fiscal_year: $('#aisSsoFiscalYear').val(),
        cycle_id: $('#aisSsoFilterCycle').val() || '',
        department_id: $('#aisSsoFilterDepartment').val() || '',
        team_id: $('#aisSsoFilterTeam').val() || '',
        branch_id: $('#aisSsoFilterBranch').val() || '',
        role_id: $('#aisSsoFilterRole').val() || '',
        employee_status: $('#aisSsoFilterStatus').val() || '',
    };
}
function aisSsoUpdateClearFilterVisibility() {
    const f = aisSsoCurrentFilters();
    const hasFilter = !!(f.cycle_id || f.department_id || f.team_id || f.branch_id || f.role_id || f.employee_status);
    $('#aisSsoFilterClearRow').toggleClass('d-none', !hasFilter);
}
function loadAisSsoFiscalYears() {
    $.ajax({
        url: `${BASE_URL}/api/annual-income-summary.years`, method: 'GET', dataType: 'json',
        success: function (res) {
            if (!res.status) return;
            const $select = $('#aisSsoFiscalYear').empty();
            const years = res.data && res.data.length ? res.data : [new Date().getFullYear()];
            years.forEach(y => $select.append(new Option('FY ' + y, y)));
            loadAisSsoSummary();
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
}
function loadAisSsoSummary() {
    const filters = aisSsoCurrentFilters();
    if (!filters.fiscal_year) return;
    $('#ais-sso-pane .ais-table-wrap').addClass('d-none');
    $('#aisSsoTableEmpty').addClass('d-none');
    $.ajax({
        url: `${BASE_URL}/api/annual-income-summary.sso-summary`, method: 'GET', dataType: 'json', data: filters,
        success: function (res) {
            if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred while loading the data.'); return; }
            $('#aisSsoSummaryEmployeeCount').text(res.data.totals.employee_count || 0);
            $('#aisSsoSummaryTotal').text(aisFmt(res.data.totals.annual_sso_amount));
            aisRenderSsoTable(res.data);
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
}
function aisSsoCellHtml(row, val, month) {
    if (!val) return '<span class="text-muted">-</span>';
    return `<button type="button" class="ais-cell-clickable" data-employee-id="${row.employee_id}" data-year="${month.year}" data-month="${month.month}">
        <span class="ais-cell-net">${aisFmt(val)}</span>
    </button>`;
}
function aisRenderSsoTable(data) {
    const months = data.months || [];
    const employees = data.employees || [];

    if (aisSsoTable) {
        aisSsoTable.destroy();
        aisSsoTable = null;
        $('#tb_ais_sso').empty().append('<thead></thead><tfoot></tfoot>');
    }
    if (!employees.length) {
        $('#aisSsoTableEmpty').removeClass('d-none');
        $('#ais-sso-pane .ais-table-wrap').addClass('d-none');
        return;
    }
    $('#ais-sso-pane .ais-table-wrap').removeClass('d-none');

    let headHtml = '<tr><th>' + (langData['employee_no'] || 'Employee No.') + '</th>'
        + '<th>' + (langData['employee'] || 'Employee') + '</th>'
        + '<th>' + (langData['department'] || 'Department') + '</th>'
        + '<th>' + (langData['team'] || 'Team') + '</th>'
        + '<th>' + (langData['position'] || 'Position') + '</th>';
    months.forEach(m => { headHtml += `<th class="ais-month-${m.state}">${escapeHtml(aisMonthLabel(m))}</th>`; });
    headHtml += '<th>' + (langData['annual_total'] || 'Annual Total') + '</th></tr>';
    $('#tb_ais_sso thead').html(headHtml);

    let footHtml = '<tr><td>' + (langData['total'] || 'Total') + '</td><td></td><td></td><td></td><td></td>';
    months.forEach(m => { footHtml += `<td class="text-end">${aisFmt((data.totals.months || {})[m.key] || 0)}</td>`; });
    footHtml += `<td class="text-end"><span class="ais-total-value">${aisFmt(data.totals.annual_sso_amount)}</span></td></tr>`;
    $('#tb_ais_sso tfoot').html(footHtml);

    // 2026-09-12, Batch 5 item 5 step 2 -- same avatar+name Employee column as Tab 1/2 above
    // (apvPersonLineHtml(), app.js -- no second avatar function).
    const columns = [
        { data: null, render: (row) => `<span class="ais-employee-no">${escapeHtml(row.employee_no)}</span>` },
        {
            data: null,
            render: {
                display: (row) => apvPersonLineHtml((currentLang === 'th' ? row.name_th : row.name_en) || row.name_th || row.name_en || '', 32, row.profile_photo_path, row.employee_id ? { employeeId: row.employee_id } : null),
                sort: (row) => (currentLang === 'th' ? row.name_th : row.name_en) || row.name_th || row.name_en || '',
                filter: (row) => (currentLang === 'th' ? row.name_th : row.name_en) || row.name_th || row.name_en || '',
            }
        },
        { data: null, render: (row) => escapeHtml((currentLang === 'th' ? row.department_name_th : row.department_name_en) || row.department_name_th || '-') },
        { data: null, render: (row) => escapeHtml((currentLang === 'th' ? row.team_name_th : row.team_name_en) || row.team_name_th || '-') },
        { data: null, render: (row) => escapeHtml((currentLang === 'th' ? row.position_name_th : row.position_name_en) || row.position_name_th || '-') },
    ];
    months.forEach(function (m, idx) {
        columns.push({
            data: null, className: 'text-end',
            render: { display: (row) => aisSsoCellHtml(row, row.months[idx], m), sort: (row) => row.months[idx] || 0, filter: (row) => row.months[idx] || 0 }
        });
    });
    columns.push({
        data: null, className: 'text-end',
        render: { display: (row) => `<span class="ais-total-value">${aisFmt(row.annual_sso_amount)}</span>`, sort: (row) => row.annual_sso_amount, filter: (row) => row.annual_sso_amount }
    });

    // 2026-09-12, Batch 5 item 5 step 1 (step 3/4 follow-up) -- routed through the shared
    // initSharedDataTable() helper, same reasoning as Tab 1/2 above.
    aisSsoTable = initSharedDataTable('#tb_ais_sso', {
        dtOptions: {
            data: employees, columns: columns, paging: false, info: false, order: [],
            drawCallback: function () { initStickyColumns('#tb_ais_sso', { left: 2, right: 1 }); },
            initComplete: function () { initTableDragScroll('#tb_ais_sso'); },
        },
    });
    updateText($('#tb_ais_sso')[0]);
}

/* ==================== Tab 3: Monthly Withholding Tax (Phase 4, T026) ====================
   Plain calendar year+month, not the fiscal-year abstraction -- see AnnualIncomeSummaryModel::
   monthlyPitDetail()'s own docblock. A flat client-side table (no month columns to freeze, so no
   FixedColumns needed here), same #tb_employee-style plain table this app otherwise defaults to.
   ==================== */
let aisMonthlyLoaded = false;
let aisMonthlyTable = null;

function aisMonthlyCurrentFilters() {
    return {
        year: $('#aisMonthlyYear').val(),
        month: $('#aisMonthlyMonth').val(),
        cycle_id: $('#aisMonthlyFilterCycle').val() || '',
        department_id: $('#aisMonthlyFilterDepartment').val() || '',
        team_id: $('#aisMonthlyFilterTeam').val() || '',
        branch_id: $('#aisMonthlyFilterBranch').val() || '',
        role_id: $('#aisMonthlyFilterRole').val() || '',
        // 2026-09-12, Batch 5 item 5 step 2 -- genuinely missing before this (Branch above already
        // existed; Status did not -- see the view's own comment on this correction).
        employee_status: $('#aisMonthlyFilterStatus').val() || '',
    };
}
function aisMonthlyUpdateClearFilterVisibility() {
    const f = aisMonthlyCurrentFilters();
    const hasFilter = !!(f.cycle_id || f.department_id || f.team_id || f.branch_id || f.role_id || f.employee_status);
    $('#aisMonthlyFilterClearRow').toggleClass('d-none', !hasFilter);
}
function loadAisMonthlyYears() {
    $.ajax({
        url: `${BASE_URL}/api/annual-income-summary.calendar-years`, method: 'GET', dataType: 'json',
        success: function (res) {
            if (!res.status) return;
            const $select = $('#aisMonthlyYear').empty();
            const currentYear = new Date().getFullYear();
            const years = res.data && res.data.length ? res.data : [currentYear];
            years.forEach(y => $select.append(new Option(y, y)));
            const currentMonth = new Date().getMonth() + 1;
            $('#aisMonthlyMonth').val(String(currentMonth)).trigger('change');
            loadAisMonthlySummary();
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
}
function loadAisMonthlySummary() {
    const filters = aisMonthlyCurrentFilters();
    if (!filters.year || !filters.month) return;
    $.ajax({
        url: `${BASE_URL}/api/annual-income-summary.monthly-pit`, method: 'GET', dataType: 'json', data: filters,
        success: function (res) {
            if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred while loading the data.'); return; }
            const totals = res.data.totals || {};
            $('#aisMonthlySummaryEmployeeCount').text(totals.employee_count || 0);
            $('#aisMonthlySummaryGross').text(aisFmt(totals.gross));
            $('#aisMonthlySummaryDeduction').text(aisFmt(totals.deduction));
            $('#aisMonthlySummaryTax').text(aisFmt(totals.tax_withheld));
            aisRenderMonthlyTable(res.data.employees || []);
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
}
// 2026-08-30, real gap avoided (same convention this app just finished auditing for app-wide --
// see docs/ui-standards.md's own "DataTable default page length" section): every list table must
// be a real DataTable, not a hand-appended <tbody>, and must default to 50/page like every other
// table -- built as one from the start here rather than a plain table that would need fixing later.
function aisRenderMonthlyTable(employees) {
    if (aisMonthlyTable) {
        aisMonthlyTable.destroy();
        aisMonthlyTable = null;
        $('#tb_ais_monthly tbody').empty();
    }
    // 2026-09-08: toggles the wrapper (once initTableDragScroll has created it, so its own pt-2 mb-5
    // padding/spacing disappears along with the table instead of leaving an empty padded box behind) or
    // the bare <table> itself (before that wrapper exists yet -- a genuinely first-ever "no data"
    // render, where `.parent()` is still whatever plain container the view puts it in).
    const $aisMonthlyHideTarget = $('#tb_ais_monthly').parent().hasClass('table-responsive') ? $('#tb_ais_monthly').parent() : $('#tb_ais_monthly');
    if (!employees.length) {
        $('#aisMonthlyTableEmpty').removeClass('d-none');
        $aisMonthlyHideTarget.addClass('d-none');
        return;
    }
    $('#aisMonthlyTableEmpty').addClass('d-none');
    $aisMonthlyHideTarget.removeClass('d-none');
    // 2026-09-12, Batch 5 item 5 step 1 (step 3/4 follow-up) -- routed through the shared
    // initSharedDataTable() helper, same reasoning as Tab 1/2/3 above -- pageLength/lengthMenu (this
    // tab's own paginated shape, unlike Tab 1/2/3's paging:false) passed as explicit overrides too,
    // even though they happen to match the helper's own defaults, per this table's "keep its own
    // real paging option visible at its own call site" requirement.
    // 2026-09-12, step 2 -- same avatar+name Employee column as Tab 1/2/3 above (apvPersonLineHtml(),
    // app.js -- no second avatar function).
    aisMonthlyTable = initSharedDataTable('#tb_ais_monthly', {
        dtOptions: {
            data: employees,
            pageLength: pageLength,
            lengthMenu: lengthMenu,
            // 2026-09-08, explicit follow-up request: "แยก code กับ ชื่อพนักงานเป็นคนละ column กันครับ แผนก ทีม
            // ตำแหน่ง ไม่ต้อง fixed column" -- same split as Tab 1/2; Department/Team/Position were never
            // part of this tab's own frozen group anyway (only Employee was, see left:1 below).
            columns: [
                { data: null, render: (row) => `<span class="ais-employee-no">${escapeHtml(row.employee_no)}</span>` },
                {
                    data: null,
                    render: {
                        display: (row) => apvPersonLineHtml((currentLang === 'th' ? row.name_th : row.name_en) || row.name_th || row.name_en || '', 32, row.profile_photo_path, row.employee_id ? { employeeId: row.employee_id } : null),
                        sort: (row) => (currentLang === 'th' ? row.name_th : row.name_en) || row.name_th || row.name_en || '',
                        filter: (row) => (currentLang === 'th' ? row.name_th : row.name_en) || row.name_th || row.name_en || '',
                    }
                },
                { data: null, render: (row) => escapeHtml((currentLang === 'th' ? row.department_name_th : row.department_name_en) || row.department_name_th || '-') },
                { data: null, render: (row) => escapeHtml((currentLang === 'th' ? row.team_name_th : row.team_name_en) || row.team_name_th || '-') },
                { data: null, render: (row) => escapeHtml((currentLang === 'th' ? row.position_name_th : row.position_name_en) || row.position_name_th || '-') },
                { data: 'gross_amount', className: 'text-end', render: (v) => aisFmt(v) },
                { data: 'total_deduction_amount', className: 'text-end', render: (v) => aisFmt(v) },
                { data: 'net_amount', className: 'text-end', render: (v) => aisFmt(v) },
                { data: 'tax_withheld', className: 'text-end', render: (v) => `<span class="fw-semibold">${aisFmt(v)}</span>` },
            ],
            // 2026-09-08, explicit follow-up request ("ทั้ง 3 Tab พนักงาน fixed...ใช้เมาส์ลากดูได้เหมือนหน้า
            // employee tab ตรวจสอบข้อมูล") -- this tab has no month matrix/Annual Total column (a single
            // calendar-month snapshot, not a 12-month spread), so only Employee No.+Employee are frozen
            // (left:2, no right) -- the same drag-scroll/sticky-column mechanism as Tab 1/2 above, applied
            // for consistency across all 3 tabs of this page even though 9 plain columns rarely need
            // horizontal scroll on a typical desktop width. `pt-2` on the new wrapper (initTableDragScroll's
            // 2nd param) matches the top padding the STATIC `.table-responsive` wrapper this table used to
            // sit in (removed from the view -- see that file's own comment) already had -- `p-3`'s own
            // left/right component was dropped same-day (explicit follow-up: "เอา p-3 ออกครับ ความกว้าง
            // ตารางไม่ตรงกับ header"): it inset this wrapper an extra layer beyond the filter/stat-card rows
            // above it, which don't have that same extra inset.
            drawCallback: function () { initStickyColumns('#tb_ais_monthly', { left: 2 }); },
            // 2026-09-08: 'mb-5' added to the dynamically-created wrapper's own classes now that the
            // outer `.card-surface p-0 mb-5` this table used to sit in is gone from the view (explicit
            // request: "card-surface p-0 mb-5 ไม่เอาครับ") -- keeps the same spacing before whatever
            // section follows without needing that wrapper back.
            initComplete: function () { initTableDragScroll('#tb_ais_monthly', 'pt-2 mb-5'); },
        },
    });
    updateText($('#tb_ais_monthly')[0]);
}

$(document).on('click', '#aisPitStationFilterToggle', function () {
    const $filter = $('#aisPitStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('change', '#aisPitFiscalYear, #aisPitFilterCycle, #aisPitFilterDepartment, #aisPitFilterTeam, #aisPitFilterBranch, #aisPitFilterRole, #aisPitFilterStatus', function () {
    aisPitUpdateClearFilterVisibility();
    loadAisPitSummary();
});
$(document).on('click', '#aisPitClearFilterBtn', function () {
    $('#aisPitFilterCycle, #aisPitFilterDepartment, #aisPitFilterTeam, #aisPitFilterBranch, #aisPitFilterRole').val(null).trigger('change.select2');
    $('#aisPitFilterStatus').val('').trigger('change');
});

$(document).on('click', '#aisSsoStationFilterToggle', function () {
    const $filter = $('#aisSsoStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('change', '#aisSsoFiscalYear, #aisSsoFilterCycle, #aisSsoFilterDepartment, #aisSsoFilterTeam, #aisSsoFilterBranch, #aisSsoFilterRole, #aisSsoFilterStatus', function () {
    aisSsoUpdateClearFilterVisibility();
    loadAisSsoSummary();
});
$(document).on('click', '#aisSsoClearFilterBtn', function () {
    $('#aisSsoFilterCycle, #aisSsoFilterDepartment, #aisSsoFilterTeam, #aisSsoFilterBranch, #aisSsoFilterRole').val(null).trigger('change.select2');
    $('#aisSsoFilterStatus').val('').trigger('change');
});

$(document).on('click', '#aisMonthlyStationFilterToggle', function () {
    const $filter = $('#aisMonthlyStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('change', '#aisMonthlyYear, #aisMonthlyMonth, #aisMonthlyFilterCycle, #aisMonthlyFilterDepartment, #aisMonthlyFilterTeam, #aisMonthlyFilterBranch, #aisMonthlyFilterRole, #aisMonthlyFilterStatus', function () {
    aisMonthlyUpdateClearFilterVisibility();
    loadAisMonthlySummary();
});
$(document).on('click', '#aisMonthlyClearFilterBtn', function () {
    $('#aisMonthlyFilterCycle, #aisMonthlyFilterDepartment, #aisMonthlyFilterTeam, #aisMonthlyFilterBranch, #aisMonthlyFilterRole').val(null).trigger('change.select2');
    $('#aisMonthlyFilterStatus').val('').trigger('change');
});

// Lazy-init every non-default tab (including the SSO tab added in Batch 2, item 6) on first
// shown.bs.tab (same "DataTable built while display:none collapses every column" gotcha this app
// has hit and documented many times already -- see docs/ui-standards.md).
$(document).on('shown.bs.tab', '#ais-pit-tab', function () {
    if (aisPitLoaded) return;
    aisPitLoaded = true;
    if (typeof initSelect2 === 'function') {
        initSelect2('#aisPitFilterCycle', { mode: 'ajax', allowClear: true });
        initSelect2('#aisPitFilterDepartment', { mode: 'ajax', allowClear: true });
        initSelect2('#aisPitFilterTeam', { mode: 'ajax', allowClear: true });
        initSelect2('#aisPitFilterBranch', { mode: 'ajax', allowClear: true });
        initSelect2('#aisPitFilterRole', { mode: 'ajax', allowClear: true });
        initSelect2('#aisPitFilterStatus', { mode: 'static' });
    }
    loadAisPitFiscalYears();
});
$(document).on('shown.bs.tab', '#ais-sso-tab', function () {
    if (aisSsoLoaded) return;
    aisSsoLoaded = true;
    if (typeof initSelect2 === 'function') {
        initSelect2('#aisSsoFilterCycle', { mode: 'ajax', allowClear: true });
        initSelect2('#aisSsoFilterDepartment', { mode: 'ajax', allowClear: true });
        initSelect2('#aisSsoFilterTeam', { mode: 'ajax', allowClear: true });
        initSelect2('#aisSsoFilterBranch', { mode: 'ajax', allowClear: true });
        initSelect2('#aisSsoFilterRole', { mode: 'ajax', allowClear: true });
        initSelect2('#aisSsoFilterStatus', { mode: 'static' });
    }
    loadAisSsoFiscalYears();
});
$(document).on('shown.bs.tab', '#ais-monthly-pit-tab', function () {
    if (aisMonthlyLoaded) return;
    aisMonthlyLoaded = true;
    if (typeof initSelect2 === 'function') {
        initSelect2('#aisMonthlyFilterCycle', { mode: 'ajax', allowClear: true });
        initSelect2('#aisMonthlyFilterDepartment', { mode: 'ajax', allowClear: true });
        initSelect2('#aisMonthlyFilterTeam', { mode: 'ajax', allowClear: true });
        initSelect2('#aisMonthlyFilterBranch', { mode: 'ajax', allowClear: true });
        initSelect2('#aisMonthlyFilterRole', { mode: 'ajax', allowClear: true });
        initSelect2('#aisMonthlyMonth', { mode: 'static' });
        initSelect2('#aisMonthlyFilterStatus', { mode: 'static' });
    }
    loadAisMonthlyYears();
});

$(document).ready(function () {
    (window.langReady || Promise.resolve()).then(function () {
    if (typeof initSelect2 === 'function') {
        initSelect2('#aisFilterCycle', { mode: 'ajax', allowClear: true });
        initSelect2('#aisFilterDepartment', { mode: 'ajax', allowClear: true });
        initSelect2('#aisFilterTeam', { mode: 'ajax', allowClear: true });
        initSelect2('#aisFilterBranch', { mode: 'ajax', allowClear: true });
        initSelect2('#aisFilterRole', { mode: 'ajax', allowClear: true });
        initSelect2('#aisFilterStatus', { mode: 'static' });
    }
    loadAisFiscalYears();
    });
});
