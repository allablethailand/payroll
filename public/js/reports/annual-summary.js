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

function aisFmt(n) {
    const v = Number(n) || 0;
    return v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function aisMonthLabel(m) {
    const monthKey = 'month_' + m.month;
    const name = langData[monthKey] || m.month;
    return `${name} ${m.year}`;
}
function escapeHtmlAis(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}
function aisMoneyCellHtml(cell) {
    if (!cell || (!cell.gross && !cell.deduction && !cell.net)) {
        return '<span class="text-muted">-</span>';
    }
    return `<span class="ais-cell-sub">+${aisFmt(cell.gross)}</span>
        <span class="ais-cell-sub ais-cell-deduction">-${aisFmt(cell.deduction)}</span>
        <span class="ais-cell-net">${aisFmt(cell.net)}</span>`;
}

function aisCurrentFilters() {
    return {
        fiscal_year: $('#aisFiscalYear').val(),
        department_id: $('#aisFilterDepartment').val() || '',
        team_id: $('#aisFilterTeam').val() || '',
        branch_id: $('#aisFilterBranch').val() || '',
        role_id: $('#aisFilterRole').val() || '',
        employee_status: $('#aisFilterStatus').val() || '',
    };
}
function aisUpdateClearFilterVisibility() {
    const f = aisCurrentFilters();
    const hasFilter = !!(f.department_id || f.team_id || f.branch_id || f.role_id || f.employee_status);
    $('#aisClearFilterBtn').toggleClass('d-none', !hasFilter);
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
    let headHtml = '<tr><th>' + (langData['employee'] || 'Employee') + '</th>';
    months.forEach(function (m) {
        headHtml += `<th class="ais-month-${m.state}">${escapeHtmlAis(aisMonthLabel(m))}</th>`;
    });
    headHtml += '<th>' + (langData['annual_total'] || 'Annual Total') + '</th></tr>';
    $('#tb_annual_summary thead').html(headHtml);

    // ---- foot (real totals from the server -- reflects every filtered employee, not just what
    // DataTable's own client-side search box currently shows) ----
    let footHtml = '<tr><td>' + (langData['total'] || 'Total') + '</td>';
    months.forEach(function (m) {
        const mt = data.totals.months[m.key] || { gross: 0, deduction: 0, net: 0 };
        footHtml += `<td>${aisMoneyCellHtml(mt)}</td>`;
    });
    footHtml += `<td>
        <span class="ais-cell-sub">+${aisFmt(data.totals.annual_gross)}</span>
        <span class="ais-cell-sub ais-cell-deduction">-${aisFmt(data.totals.annual_deduction)}</span>
        <span class="ais-total-value">${aisFmt(data.totals.annual_net)}</span>
    </td></tr>`;
    $('#tb_annual_summary tfoot').html(footHtml);

    // ---- columns ----
    const columns = [
        {
            data: null,
            render: function (row) {
                const name = currentLang === 'th' ? row.name_th : row.name_en;
                return `<div class="ais-employee-no">${escapeHtmlAis(row.employee_no)}</div>
                    <div class="ais-employee-name">${escapeHtmlAis(name || row.name_th || row.name_en || '')}</div>`;
            }
        }
    ];
    months.forEach(function (m, idx) {
        // Object-form render (display/sort/filter split, same DataTables sort-safety convention
        // this app already uses for formatted date columns) -- sorting/filtering a money column by
        // its comma-formatted display string would sort lexicographically instead of numerically.
        columns.push({
            data: null,
            render: {
                display: (row) => aisMoneyCellHtml(row.months[idx]),
                sort: (row) => row.months[idx] ? row.months[idx].net : 0,
                filter: (row) => row.months[idx] ? row.months[idx].net : 0,
            }
        });
    });
    columns.push({
        data: null,
        render: {
            display: (row) => `<span class="ais-cell-sub">+${aisFmt(row.annual_gross)}</span>
                <span class="ais-cell-sub ais-cell-deduction">-${aisFmt(row.annual_deduction)}</span>
                <span class="ais-total-value">${aisFmt(row.annual_net)}</span>`,
            sort: (row) => row.annual_net,
            filter: (row) => row.annual_net,
        }
    });

    aisTable = $('#tb_annual_summary').DataTable({
        data: employees,
        columns: columns,
        destroy: true,
        paging: false,
        info: false,
        order: [],
        scrollX: true,
        scrollY: '60vh',
        scrollCollapse: true,
        fixedColumns: { left: 1, right: 1 },
        language: getTableLang(),
    });
    updateText($('#tb_annual_summary')[0]);
}

$(document).on('click', '#aisStationFilterToggle', function () {
    const $filter = $('#aisStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('change', '#aisFiscalYear, #aisFilterDepartment, #aisFilterTeam, #aisFilterBranch, #aisFilterRole, #aisFilterStatus', function () {
    aisUpdateClearFilterVisibility();
    loadAisSummary();
});
$(document).on('click', '#aisClearFilterBtn', function () {
    $('#aisFilterDepartment, #aisFilterTeam, #aisFilterBranch, #aisFilterRole').val(null).trigger('change.select2');
    $('#aisFilterStatus').val('').trigger('change');
});

// 2026-08-29, explicit follow-up: "ยังไม่มีหน้าตั้งค่าการตัดรอบปี" -- quick-access Settings modal on
// this page's own header. Loads the current value fresh every time the modal opens (not cached
// from page load) so it's never stale if changed from Company Profile in another tab.
$(document).on('show.bs.modal', '#aisFiscalYearSettingsModal', function () {
    $.ajax({
        url: `${BASE_URL}/api/annual-income-summary.fiscal-year-setting`, method: 'GET', dataType: 'json',
        success: function (res) {
            if (res.status) {
                $('#aisFiscalYearStartMonth').val(String((res.data || {}).fiscal_year_start_month || 1)).trigger('change');
            }
        }
    });
});
$(document).on('click', '#btnSaveAisFiscalYearSetting', function () {
    const month = parseInt($('#aisFiscalYearStartMonth').val(), 10) || 1;
    const $btn = $(this);
    $btn.prop('disabled', true);
    $.ajax({
        url: `${BASE_URL}/api/annual-income-summary.fiscal-year-setting.save`, method: 'POST', contentType: 'application/json', dataType: 'json',
        data: JSON.stringify({ fiscal_year_start_month: month }),
        success: function (res) {
            $btn.prop('disabled', false);
            if (res.status) {
                showSuccess(langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('aisFiscalYearSettingsModal')).hide();
                // The fiscal year boundaries themselves may have just changed (e.g. April -> January)
                // -- reload the year list AND the currently-displayed summary so the page reflects
                // the new setting immediately instead of requiring a manual refresh.
                loadAisFiscalYears();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () { $btn.prop('disabled', false); showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
});

$(document).ready(function () {
    if (typeof initSelect2 === 'function') {
        initSelect2('#aisFilterDepartment', { mode: 'ajax', allowClear: true });
        initSelect2('#aisFilterTeam', { mode: 'ajax', allowClear: true });
        initSelect2('#aisFilterBranch', { mode: 'ajax', allowClear: true });
        initSelect2('#aisFilterRole', { mode: 'ajax', allowClear: true });
        initSelect2('#aisFilterStatus', { mode: 'static' });
        initSelect2('#aisFiscalYearStartMonth', { mode: 'static' });
    }
    loadAisFiscalYears();
});
