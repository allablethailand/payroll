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
// 2026-09-12, Batch 5 item 6 -- module-level so this SURVIVES a year/filter re-render (explicit
// instruction: the display-toggle state must not reset on reload) -- aisRenderMetricTable() only ever
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
    return fmtNum(v);
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

// The filter-bar's "no filter" status option is the value 'all'; the API expects it empty.
function aisStatusFilterValue(selector) {
    const v = $(selector).val();
    return v === 'all' ? '' : (v || '');
}

// Shared by the Annual Summary and Monthly Withholding Tax filter bars (same field set, different id prefix).
function aisFilterValues(prefix) {
    return {
        cycle_id: $(`#${prefix}Cycle`).val() || '',
        department_id: $(`#${prefix}Department`).val() || '',
        team_id: $(`#${prefix}Team`).val() || '',
        branch_id: $(`#${prefix}Branch`).val() || '',
        role_id: $(`#${prefix}Role`).val() || '',
        employee_status: aisStatusFilterValue(`#${prefix}Status`),
    };
}
function aisInitFilterSelects(prefix) {
    if (typeof initSelect2 !== 'function') return;
    ['Cycle', 'Department', 'Team', 'Branch', 'Role'].forEach(function (s) {
        initSelect2(`#${prefix}${s}`, { mode: 'ajax', allowClear: true });
    });
    initSelect2(`#${prefix}Status`, { mode: 'static' });
}
function aisCurrentFilters() {
    return Object.assign({ fiscal_year: $('#aisFiscalYear').val() }, aisFilterValues('aisFilter'));
}

/* ==================== Annual Summary: one filter bar, one fiscal year, one table per metric ====================
   Income / Tax / Social Security share the filters, the fiscal-year select and the card row; each metric
   has its own pill pane + table and its own API endpoint (summary / pit-summary / sso-summary). A metric's
   table is (re)built only while its pane is visible (first shown.bs.tab, or after a filter/year change made
   since it last loaded); showing an already-current metric just re-measures columns. */
function aisSimpleMonthCellHtml(row, val, month) {
    if (!val) return '<span class="text-muted">-</span>';
    return `<button type="button" class="ais-cell-clickable" data-employee-id="${row.employee_id}" data-year="${month.year}" data-month="${month.month}">
        <span class="ais-cell-net">${aisFmt(val)}</span>
    </button>`;
}
function aisSimpleMetric(opts) {
    return Object.assign({
        monthCell: (row, idx, m) => aisSimpleMonthCellHtml(row, row.months[idx], m),
        monthSort: (row, idx) => row.months[idx] || 0,
        footMonth: (data, m) => aisFmt((data.totals.months || {})[m.key] || 0),
        totalCell: (row) => `<span class="ais-total-value">${aisFmt(row[opts.totalKey])}</span>`,
        totalSort: (row) => row[opts.totalKey],
        footTotal: (data) => `<span class="ais-total-value">${aisFmt(data.totals[opts.totalKey])}</span>`,
    }, opts);
}
const AIS_METRICS = {
    income: {
        endpoint: 'summary', table: '#tb_annual_summary', pane: '#ais-metric-income-pane', empty: '#aisTableEmpty',
        monthCell: (row, idx, m) => aisEmployeeMonthCellHtml(row, row.months[idx], m),
        monthSort: (row, idx) => row.months[idx] ? row.months[idx].net : 0,
        footMonth: (data, m) => aisMoneyCellHtml(data.totals.months[m.key] || { gross: 0, deduction: 0, net: 0 }),
        totalCell: (row) => aisAnnualTotalCellHtml(row),
        totalSort: (row) => row.annual_net,
        footTotal: (data) => `<span class="ais-cell-sub">+${aisFmt(data.totals.annual_gross)}</span>
        <span class="ais-cell-sub ais-cell-deduction">-${aisFmt(data.totals.annual_deduction)}</span>
        <span class="ais-total-value">${aisFmt(data.totals.annual_net)}</span>`,
        renderCards: function (t) {
            $('#aisSummaryGross').text(aisFmt(t.annual_gross));
            $('#aisSummaryDeduction').text(aisFmt(t.annual_deduction));
            $('#aisSummaryNet').text(aisFmt(t.annual_net));
        },
    },
    pit: aisSimpleMetric({
        endpoint: 'pit-summary', table: '#tb_ais_pit', pane: '#ais-metric-pit-pane', empty: '#aisPitTableEmpty',
        totalKey: 'annual_tax_withheld',
        renderCards: (t) => $('#aisSummaryTax').text(aisFmt(t.annual_tax_withheld)),
    }),
    sso: aisSimpleMetric({
        endpoint: 'sso-summary', table: '#tb_ais_sso', pane: '#ais-metric-sso-pane', empty: '#aisSsoTableEmpty',
        totalKey: 'annual_sso_amount',
        renderCards: (t) => $('#aisSummarySso').text(aisFmt(t.annual_sso_amount)),
    }),
};
Object.keys(AIS_METRICS).forEach(function (k) { AIS_METRICS[k].dt = null; AIS_METRICS[k].loadedVersion = -1; });
// Bumped on every filter/year change; a metric whose loadedVersion lags behind reloads when next shown.
let aisVersion = 0;

function aisActiveMetric() {
    return $('#aisMetricTabs .nav-link.active').data('ais-metric-tab') || 'income';
}
function aisShowMetricCards(key) {
    $('#aisSummaryCards [data-ais-metric]').each(function () {
        $(this).toggleClass('d-none', $(this).data('ais-metric') !== key);
    });
}

function loadAisFiscalYears() {
    $.ajax({
        url: `${BASE_URL}/api/annual-income-summary.years`,
        method: 'GET',
        dataType: 'json',
        success: function (res) {
            if (!res.status) return;
            const $select = $('#aisFiscalYear').empty();
            const years = res.data && res.data.length ? res.data : [new Date().getFullYear()];
            years.forEach(function (y) {
                $select.append(new Option('FY ' + y, y));
            });
            loadAisMetric(aisActiveMetric());
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while loading the data.');
        }
    });
}

function aisReloadActiveMetric() {
    aisVersion++;
    loadAisMetric(aisActiveMetric());
}

function loadAisMetric(key) {
    const cfg = AIS_METRICS[key];
    const filters = aisCurrentFilters();
    if (!filters.fiscal_year) return;
    cfg.loadedVersion = aisVersion;
    $(`${cfg.pane} .ais-table-wrap`).addClass('d-none');
    $(cfg.empty).addClass('d-none');
    $.ajax({
        url: `${BASE_URL}/api/annual-income-summary.${cfg.endpoint}`,
        method: 'GET',
        dataType: 'json',
        data: filters,
        success: function (res) {
            if (!res.status) {
                cfg.loadedVersion = -1;
                showWarning(res.message || langData['save_failed'] || 'An error occurred while loading the data.');
                return;
            }
            $('#aisSummaryEmployeeCount').text(res.data.totals.employee_count || 0);
            cfg.renderCards(res.data.totals);
            aisRenderMetricTable(key, res.data);
        },
        error: function () {
            cfg.loadedVersion = -1;
            showWarning(langData['save_failed'] || 'An error occurred while loading the data.');
        }
    });
}

function aisIdentityColumns() {
    const nameOf = (row) => (currentLang === 'th' ? row.name_th : row.name_en) || row.name_th || row.name_en || '';
    const lbl = (row, k) => escapeHtml((currentLang === 'th' ? row[k + '_name_th'] : row[k + '_name_en']) || row[k + '_name_th'] || '-');
    return [
        { data: null, render: (row) => `<span class="ais-employee-no">${escapeHtml(row.employee_no)}</span>` },
        {
            data: null,
            render: {
                display: (row) => apvPersonLineHtml(nameOf(row), 32, row.profile_photo_path, row.employee_id ? { employeeId: row.employee_id } : null),
                sort: nameOf,
                filter: nameOf,
            }
        },
        { data: null, render: (row) => lbl(row, 'department') },
        { data: null, render: (row) => lbl(row, 'team') },
        { data: null, render: (row) => lbl(row, 'position') },
    ];
}

function aisRenderMetricTable(key, data) {
    const cfg = AIS_METRICS[key];
    const months = data.months || [];
    const employees = data.employees || [];
    if (key === 'income') {
        // Kept for the Annual Total click-through modal (reads the CURRENTLY shown data, no extra endpoint).
        aisCurrentMonths = months;
        aisCurrentEmployeesById = {};
        employees.forEach(function (e) { aisCurrentEmployeesById[e.employee_id] = e; });
    }

    if (cfg.dt) {
        cfg.dt.destroy();
        cfg.dt = null;
        $(cfg.table).empty().append('<thead></thead><tfoot></tfoot>');
    }
    if (!employees.length) {
        $(cfg.empty).removeClass('d-none');
        $(`${cfg.pane} .ais-table-wrap`).addClass('d-none');
        return;
    }
    $(`${cfg.pane} .ais-table-wrap`).removeClass('d-none');

    // Employee No./Employee are frozen left, Annual Total frozen right; Department/Team/Position scroll with the months.
    let headHtml = '<tr><th>' + (langData['employee_no'] || 'Employee No.') + '</th>'
        + '<th>' + (langData['employee'] || 'Employee') + '</th>'
        + '<th>' + (langData['department'] || 'Department') + '</th>'
        + '<th>' + (langData['team'] || 'Team') + '</th>'
        + '<th>' + (langData['position'] || 'Position') + '</th>';
    months.forEach(function (m) {
        headHtml += `<th class="ais-month-${m.state}">${escapeHtml(aisMonthLabel(m))}</th>`;
    });
    headHtml += '<th>' + (langData['annual_total'] || 'Annual Total') + '</th></tr>';
    $(`${cfg.table} thead`).html(headHtml);

    // Footer totals come from the server (every filtered employee, not just what the search box leaves visible).
    let footHtml = '<tr><td>' + (langData['total'] || 'Total') + '</td><td></td><td></td><td></td><td></td>';
    months.forEach(function (m) { footHtml += `<td class="text-end">${cfg.footMonth(data, m)}</td>`; });
    footHtml += `<td class="text-end">${cfg.footTotal(data)}</td></tr>`;
    $(`${cfg.table} tfoot`).html(footHtml);

    // Object-form render (display/sort/filter) so money columns sort numerically, not by formatted string.
    const columns = aisIdentityColumns();
    months.forEach(function (m, idx) {
        columns.push({
            data: null,
            className: 'text-end',
            render: {
                display: (row) => cfg.monthCell(row, idx, m),
                sort: (row) => cfg.monthSort(row, idx),
                filter: (row) => cfg.monthSort(row, idx),
            }
        });
    });
    columns.push({
        data: null,
        className: 'text-end',
        render: { display: cfg.totalCell, sort: cfg.totalSort, filter: cfg.totalSort }
    });

    cfg.dt = initSharedDataTable(cfg.table, {
        dtOptions: {
            data: employees,
            columns: columns,
            paging: false,
            info: false,
            order: [],
            // Sticky columns are plain CSS (sticky-table-columns.js), not DataTables' FixedColumns, which is broken in this build.
            drawCallback: function () { initStickyColumns(cfg.table, { left: 2, right: 1 }); },
            // Department/Team/Position are the categorical columns; rebuilt on every render, so initComplete re-applies them.
            initComplete: function () {
                initExcelColumnFilters(this.api(), {
                    mode: 'client',
                    columns: [
                        { index: 2, key: 'department' },
                        { index: 3, key: 'team' },
                        { index: 4, key: 'position' },
                    ],
                });
                // Re-run after the filter icons change header widths.
                initStickyColumns(cfg.table, { left: 2, right: 1 });
                initTableDragScroll(cfg.table);
            },
        },
    });
    updateText($(cfg.table)[0]);
    // Display-toggle state (module-level) survives a reload; it only ever applies to the income table.
    if (key === 'income') applyAisColumnDisplayToggle();
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
            <div class="fw-semibold small mt-2 mb-1">${langData['breakdown_earnings'] || 'Income'}</div>
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
        const rowTag = hasData ? `<tr class="ais-cell-clickable"${clickableAttrs}${cls}>` : `<tr${cls}>`;
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


/* ==================== Tab 3: Monthly Withholding Tax (Phase 4, T026) ====================
   Plain calendar year+month, not the fiscal-year abstraction -- see AnnualIncomeSummaryModel::
   monthlyPitDetail()'s own docblock. A flat client-side table (no month columns to freeze, so no
   FixedColumns needed here), same #tb_employee-style plain table this app otherwise defaults to.
   ==================== */
let aisMonthlyLoaded = false;
let aisMonthlyTable = null;

function aisMonthlyCurrentFilters() {
    return Object.assign({ year: $('#aisMonthlyYear').val(), month: $('#aisMonthlyMonth').val() }, aisFilterValues('aisMonthlyFilter'));
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


$(document).on('change', '#aisFiscalYear', aisReloadActiveMetric);
$(document).on('change', '#aisMonthlyYear, #aisMonthlyMonth', loadAisMonthlySummary);

// A metric pill's table is built while its pane is visible: reload if filters changed since it last loaded,
// else just re-measure columns (a table sized while hidden collapses every column).
$(document).on('shown.bs.tab', '[data-ais-metric-tab]', function () {
    const key = $(this).data('ais-metric-tab');
    const cfg = AIS_METRICS[key];
    aisShowMetricCards(key);
    if (cfg.loadedVersion !== aisVersion) loadAisMetric(key);
    else if (cfg.dt) cfg.dt.columns.adjust();
});
// Annual Summary is a top tab of /reports: filters + first load happen when its pane is first visible, later shows re-measure the active table.
let aisAnnualInited = false;
function aisInitAnnual() {
    if (aisAnnualInited || !$('#aisFilterBar').length) return;
    aisAnnualInited = true;
    aisInitFilterSelects('aisFilter');
    initFilterBar('#aisFilterBar', { onChange: aisReloadActiveMetric });
    aisShowMetricCards('income');
    loadAisFiscalYears();
}
$(document).on('shown.bs.tab', '#ais-annual-tab', function () {
    if (!aisAnnualInited) { aisInitAnnual(); return; }
    const cfg = AIS_METRICS[aisActiveMetric()];
    if (cfg.dt) cfg.dt.columns.adjust();
});
// Monthly tab is lazy: filters + data load on first show, columns re-measured on later shows.
function aisInitMonthly() {
    if (aisMonthlyLoaded || !$('#aisMonthlyFilterBar').length) return;
    aisMonthlyLoaded = true;
    aisInitFilterSelects('aisMonthlyFilter');
    if (typeof initSelect2 === 'function') initSelect2('#aisMonthlyMonth', { mode: 'static' });
    initFilterBar('#aisMonthlyFilterBar', { onChange: loadAisMonthlySummary });
    loadAisMonthlyYears();
}
$(document).on('shown.bs.tab', '#ais-monthly-pit-tab', function () {
    if (!aisMonthlyLoaded) { aisInitMonthly(); return; }
    if (aisMonthlyTable) aisMonthlyTable.columns.adjust();
});

$(document).ready(function () {
    (window.langReady || Promise.resolve()).then(function () {
    if ($('#ais-annual-tab').hasClass('active')) aisInitAnnual();
    if ($('#ais-monthly-pit-tab').hasClass('active')) aisInitMonthly();
    });
});
