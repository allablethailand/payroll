// 2026-09-02, 3-way Employee submenu split -- extracted verbatim from list.js's own 9 report
// sections (Standing Items Summary through Data Completeness overview, built across Phases 0-4 of
// the Employee Reports plan -- see each section's own docblock for backend design). Now the sole
// content of its own standalone page instead of one of 4 tabs on /payroll/employees, so the only
// functional change is the page-load init at the very top of this file (was a shown.bs.tab
// lazy-init for the first sub-tab, see that block's own comment) -- every one of the 9 inner pill
// sub-tabs below is unchanged.
/* ==================== Standing Items Summary tab (2026-08-30, explicit request: "ต้องการอีก Tab
   ต่อจาก Tab ตรวจสอบข้อมูล เป็น Tab สรุปรวมรายได้รายหักที่ หักหรือได้ประจำ...ให้แสดงตัวเลขในรอบที่รอจ่าย รอหัก
   และบอกด้วยว่า งวดที่เท่าไหร่จากทั้งหมดกี่งวด และมีสรุปรวมใน Column ท้าย และ Footer") ====================
   Server-computed totals (EmployeeModel::standingSummaryList()'s own `totals` key, covering the WHOLE
   filtered set, not just the current page -- this table is serverSide:true so DataTables' own
   client-side footerCallback would only ever see the current page) rendered into a real <tfoot> via
   ajax.dataSrc, same "server totals in a real tfoot" precedent AnnualIncomeSummaryModel's own report
   already established. ==================== */
let tb_employee_summary;
function fmtMoneyList(n) {
    const v = Number(n) || 0;
    return v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
// Recurring Earnings has no installment concept (indefinite) -- Pending PED items DO ("งวดที่ X จาก Y",
// employee_earning_deductions' own total_installments/current_installment columns, no cycle-date math
// needed -- see standingSummaryForEmployees()'s own docblock for why).
function summaryBadgeHtml(items, total, opts) {
    opts = opts || {};
    if (!items || !items.length) {
        return '<span class="text-muted">-</span>';
    }
    const lines = items.map(function (it) {
        const name = currentLang === 'th' ? it.name_th : it.name_en;
        const installmentSuffix = opts.showInstallment ? ` (${it.installment_no}/${it.total_installments})` : '';
        return `${name}${installmentSuffix}: ${fmtMoneyList(it.amount)}`;
    });
    const cls = opts.deduction ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success';
    return `<span class="badge ${cls}" title="${escapeHtmlList(lines.join(' | '))}">${items.length} ${langData['items_short'] || 'item(s)'} — ${fmtMoneyList(total)}</span>`;
}
function currentEmployeeSummaryFilters() {
    return {
        role_id: $('#employee_summary_filter_role').val() || '',
        department_id: $('#employee_summary_filter_department').val() || '',
        team_id: $('#employee_summary_filter_team').val() || '',
        shift_id: $('#employee_summary_filter_shift').val() || '',
        branch_id: $('#employee_summary_filter_branch').val() || ''
    };
}
function updateClearEmployeeSummaryFilterVisibility() {
    const f = currentEmployeeSummaryFilters();
    const hasFilter = !!(f.role_id || f.department_id || f.team_id || f.shift_id || f.branch_id);
    $('#employeeSummaryFilterClearRow').toggleClass('d-none', !hasFilter);
}
// 2026-09-02, real bug found and fixed (explicit report: "ตาราง Body ไม่เท่า Footer") -- this used to
// replace the WHOLE <tfoot> row via .html() on every ajax response, which destroys and recreates
// its <td> nodes -- the Responsive extension tracks/hides SPECIFIC DOM nodes it captured when the
// table was first initialized, so a replaced footer row falls out of its tracking entirely and never
// gets its columns hidden in step with the body on a narrow viewport. Fixed by updating each footer
// cell's TEXT CONTENT in place (by id, see the static row in list.php's own comment) instead --
// same DOM nodes survive every redraw, so Responsive's hide/show keeps applying to them correctly.
function renderEmployeeSummaryFooter(totals) {
    if (!totals) return;
    $('#empSummaryFootBaseSalary').text(fmtMoneyList(totals.base_salary_amount));
    $('#empSummaryFootRecurring').text(fmtMoneyList(totals.recurring_total));
    $('#empSummaryFootRecurringDeduction').text(fmtMoneyList(totals.recurring_deduction_total));
    $('#empSummaryFootPedEarning').text(fmtMoneyList(totals.ped_earning_total));
    $('#empSummaryFootPedDeduction').text(fmtMoneyList(totals.ped_deduction_total));
    $('#empSummaryFootTotalEarning').text(fmtMoneyList(totals.total_earning));
    $('#empSummaryFootTotalDeduction').text(fmtMoneyList(totals.total_deduction));
    $('#empSummaryFootNetTotal').text(fmtMoneyList(totals.net_total));
}
// 2026-09-02, explicit request: "อยากให้มี Card Summary อยู่ที่หัวตารางครับ" -- reads the SAME
// `recordsFiltered`/`totals` values the footer above already reads from this table's own ajax
// response (server-computed across the whole filtered set, not just the current page) -- zero new
// backend call, just another place to show numbers already in hand.
function renderEmployeeSummaryCards(json) {
    $('#empSummaryCardEmployeeCount').text((Number(json.recordsFiltered) || 0).toLocaleString());
    const totals = json.totals || {};
    $('#empSummaryCardTotalEarning').text(fmtMoneyList(totals.total_earning));
    $('#empSummaryCardTotalDeduction').text(fmtMoneyList(totals.total_deduction));
    $('#empSummaryCardNetTotal').text(fmtMoneyList(totals.net_total));
}
function initEmployeeSummaryTable() {
    if ($.fn.DataTable.isDataTable('#tb_employee_summary')) {
        tb_employee_summary.ajax.reload(null, false);
        return;
    }
    tb_employee_summary = $('#tb_employee_summary').DataTable({
        serverSide: true,
        processing: true,
        ordering: false,
        responsive: { details: { type: 'column', target: 0 } },
        ajax: {
            url: `${BASE_URL}/api/employee.standing-summary-list`,
            type: 'POST',
            data: function (d) { Object.assign(d, currentEmployeeSummaryFilters()); },
            dataSrc: function (json) {
                renderEmployeeSummaryFooter(json.totals);
                renderEmployeeSummaryCards(json);
                return json.data || [];
            }
        },
        columns: [
            { data: null, orderable: false, className: 'dtr-control', defaultContent: '' },
            // 2026-08-31, explicit request: "ตารางพนักงานทุกตาราง แยก code กับชื่อเป็นคนละ Column" -- was
            // one column with employee_no/name stacked, split into 2 (matches #tb_employee's own
            // convention, and the same fix just applied to #tb_employee_recheck above).
            { data: 'employee_no', responsivePriority: 1, render: d => escapeHtmlList(d || '-') },
            { data: 'name', responsivePriority: 1, render: d => escapeHtmlList(d || '-') },
            { data: null, className: 'text-end', responsivePriority: 10, render: (d, t, row) => fmtMoneyList(row.summary ? row.summary.base_salary_amount : 0) },
            { data: null, className: 'text-end', responsivePriority: 10, render: (d, t, row) => row.summary ? summaryBadgeHtml(row.summary.recurring, row.summary.recurring_total, {}) : '-' },
            { data: null, className: 'text-end', responsivePriority: 10, render: (d, t, row) => row.summary ? summaryBadgeHtml(row.summary.recurring_deduction, row.summary.recurring_deduction_total, { deduction: true }) : '-' },
            { data: null, className: 'text-end', responsivePriority: 10, render: (d, t, row) => row.summary ? summaryBadgeHtml(row.summary.ped_earning, row.summary.ped_earning_total, { showInstallment: true }) : '-' },
            { data: null, className: 'text-end', responsivePriority: 10, render: (d, t, row) => row.summary ? summaryBadgeHtml(row.summary.ped_deduction, row.summary.ped_deduction_total, { showInstallment: true, deduction: true }) : '-' },
            { data: null, className: 'text-end fw-bold', responsivePriority: 5, render: (d, t, row) => fmtMoneyList(row.summary ? row.summary.total_earning : 0) },
            { data: null, className: 'text-end fw-bold', responsivePriority: 5, render: (d, t, row) => fmtMoneyList(row.summary ? row.summary.total_deduction : 0) },
            { data: null, className: 'text-end fw-bold', responsivePriority: 1, render: (d, t, row) => fmtMoneyList(row.summary ? row.summary.net_total : 0) },
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
    });
}
// 2026-09-02, 3-way Employee submenu split -- this used to be a shown.bs.tab lazy-init (Reports was
// one of 4 top-level tabs on /payroll/employees; Standing Items Summary was its first sub-tab,
// which needed its own DataTable built only once actually revealed). Now Reports is the whole
// content of its own standalone page (/payroll/employees/reports), visible from first paint, so
// this inits directly on page load instead -- the 9 INNER pill sub-tabs below are untouched, they
// still lazy-init on their own shown.bs.tab exactly as before, since they're still real nested
// Bootstrap tabs within this one page.
$(document).ready(function () {
    initEmployeeSummaryTable();
});
$(document).on('click', '#employeeSummaryStationFilterToggle', function () {
    const $filter = $('#employeeSummaryStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('change', '#employee_summary_filter_role, #employee_summary_filter_department, #employee_summary_filter_team, #employee_summary_filter_shift, #employee_summary_filter_branch', function () {
    updateClearEmployeeSummaryFilterVisibility();
    if (tb_employee_summary) tb_employee_summary.ajax.reload(null, true);
});
$(document).on('click', '#btnClearEmployeeSummaryFilter', function () {
    $('#employee_summary_filter_role, #employee_summary_filter_department, #employee_summary_filter_team, #employee_summary_filter_shift, #employee_summary_filter_branch').val(null).trigger('change.select2');
    updateClearEmployeeSummaryFilterVisibility();
    if (tb_employee_summary) tb_employee_summary.ajax.reload(null, true);
});
if (typeof watchTabDirty === 'function') {
    watchTabDirty('employee_list_dirty', function () {
        if ($.fn.DataTable.isDataTable('#tb_employee_summary')) {
            $('#tb_employee_summary').DataTable().ajax.reload(null, false);
        }
    });
}

/* ==================== Headcount Movement report (2026-09-02, Phase 1 of the Employee Reports
   plan) -- explicit request: "Report คนเข้าคนออกประจำเดือน ประจำปี", followed by an explicit request
   for summary cards + a chart ("ไม่อยากให้เป็นตารางโล้นๆ"). Year-scoped -- selecting a year shows
   that whole year's monthly trend, covering both the "monthly" and "annual" framing of the original
   request in one view (see EmployeeModel::headcountMovementReport()'s own docblock). ==================== */
let empHeadcountChartInstance = null;
let tb_employee_headcount_events;
function currentEmployeeHeadcountFilters() {
    return {
        year: $('#employee_headcount_filter_year').val() || new Date().getFullYear(),
        department_id: $('#employee_headcount_filter_department').val() || '',
        branch_id: $('#employee_headcount_filter_branch').val() || '',
    };
}
function updateClearEmployeeHeadcountFilterVisibility() {
    const f = currentEmployeeHeadcountFilters();
    const hasFilter = !!(f.department_id || f.branch_id);
    $('#employeeHeadcountFilterClearRow').toggleClass('d-none', !hasFilter);
}
const EMP_HEADCOUNT_MONTH_LABELS_TH = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
const EMP_HEADCOUNT_MONTH_LABELS_EN = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
function renderEmployeeHeadcountCards(summary) {
    $('#empHeadcountCardHires').text((Number(summary.total_hires) || 0).toLocaleString());
    $('#empHeadcountCardExits').text((Number(summary.total_exits) || 0).toLocaleString());
    const net = Number(summary.net_change) || 0;
    $('#empHeadcountCardNetChange').text((net > 0 ? '+' : '') + net.toLocaleString());
    $('#empHeadcountCardTurnoverRate').text((Number(summary.turnover_rate) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%');
}
function renderEmployeeHeadcountChart(byMonth) {
    const $canvas = $('#employeeHeadcountChart');
    if (!$canvas.length || typeof Chart === 'undefined') return;
    const labels = (currentLang === 'th' ? EMP_HEADCOUNT_MONTH_LABELS_TH : EMP_HEADCOUNT_MONTH_LABELS_EN);
    const hiresData = byMonth.map(r => Number(r.hires) || 0);
    const exitsData = byMonth.map(r => Number(r.exits) || 0);
    if (empHeadcountChartInstance) {
        empHeadcountChartInstance.data.labels = labels;
        empHeadcountChartInstance.data.datasets[0].data = hiresData;
        empHeadcountChartInstance.data.datasets[1].data = exitsData;
        empHeadcountChartInstance.data.datasets[0].label = langData['headcount_total_hires'] || 'Total Hires';
        empHeadcountChartInstance.data.datasets[1].label = langData['headcount_total_exits'] || 'Total Exits';
        empHeadcountChartInstance.update();
        return;
    }
    empHeadcountChartInstance = new Chart($canvas[0].getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                { label: langData['headcount_total_hires'] || 'Total Hires', data: hiresData, backgroundColor: '#198754', borderRadius: 4, maxBarThickness: 28 },
                { label: langData['headcount_total_exits'] || 'Total Exits', data: exitsData, backgroundColor: '#dc3545', borderRadius: 4, maxBarThickness: 28 },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: true, position: 'top' } },
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
        },
    });
}
function employeeHeadcountEventTypeLabel(type) {
    return type === 'hire' ? (langData['headcount_event_hire'] || 'Hire') : (langData['headcount_event_exit'] || 'Exit');
}
function initEmployeeHeadcountEventsTable(events) {
    if ($.fn.DataTable.isDataTable('#tb_employee_headcount_events')) {
        tb_employee_headcount_events.clear().rows.add(events).draw();
        return;
    }
    tb_employee_headcount_events = $('#tb_employee_headcount_events').DataTable({
        data: events,
        responsive: true,
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        order: [[0, 'desc']],
        columns: [
            {
                data: 'event_date',
                render: {
                    display: d => formatDisplayDate(d),
                    sort: d => d,
                    filter: d => d,
                }
            },
            { data: 'employee_no', render: d => escapeHtmlList(d || '-') },
            { data: 'name', render: d => escapeHtmlList(d || '-') },
            { data: null, render: (d, t, row) => escapeHtmlList((currentLang === 'th' ? row.department_name_th : row.department_name_en) || '-') },
            { data: null, render: (d, t, row) => escapeHtmlList((currentLang === 'th' ? row.branch_name_th : row.branch_name_en) || '-') },
            {
                data: 'movement_type',
                render: {
                    display: t => t === 'hire'
                        ? `<span class="badge bg-success-subtle text-success">${employeeHeadcountEventTypeLabel(t)}</span>`
                        : `<span class="badge bg-danger-subtle text-danger">${employeeHeadcountEventTypeLabel(t)}</span>`,
                    sort: t => t,
                    filter: t => employeeHeadcountEventTypeLabel(t),
                }
            },
        ],
    });
    if (typeof initExcelColumnFilters === 'function') {
        initExcelColumnFilters(tb_employee_headcount_events, { mode: 'client' });
    }
}
function loadEmployeeHeadcountReport() {
    const filters = currentEmployeeHeadcountFilters();
    $.ajax({
        url: `${BASE_URL}/api/employee.headcount-movement-report`, method: 'POST', dataType: 'json',
        data: filters,
        success: function (res) {
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            renderEmployeeHeadcountCards(res.data.summary);
            renderEmployeeHeadcountChart(res.data.by_month);
            initEmployeeHeadcountEventsTable(res.data.events || []);
        }
    });
}
$(document).on('shown.bs.tab', '#empReportSub-headcount-tab', function () {
    loadEmployeeHeadcountReport();
});
$(document).on('click', '#employeeHeadcountStationFilterToggle', function () {
    const $filter = $('#employeeHeadcountStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('change', '#employee_headcount_filter_year, #employee_headcount_filter_department, #employee_headcount_filter_branch', function () {
    updateClearEmployeeHeadcountFilterVisibility();
    loadEmployeeHeadcountReport();
});
$(document).on('click', '#btnClearEmployeeHeadcountFilter', function () {
    $('#employee_headcount_filter_department, #employee_headcount_filter_branch').val(null).trigger('change.select2');
    updateClearEmployeeHeadcountFilterVisibility();
    loadEmployeeHeadcountReport();
});

/* ==================== Expiry Alerts (2026-09-02, Phase 2 of the Employee Reports plan) --
   Contract/Work Permit/Visa/Passport, the highest-value quick win: this data has sat fully
   populated and completely unsurfaced since the 2026-09-02 sync field batch. ==================== */
let tb_employee_expiry;
function currentEmployeeExpiryFilters() {
    return {
        within_days: $('#employee_expiry_filter_within_days').val() || 90,
        department_id: $('#employee_expiry_filter_department').val() || '',
        branch_id: $('#employee_expiry_filter_branch').val() || '',
    };
}
function updateClearEmployeeExpiryFilterVisibility() {
    const f = currentEmployeeExpiryFilters();
    const hasFilter = !!(f.department_id || f.branch_id);
    $('#employeeExpiryFilterClearRow').toggleClass('d-none', !hasFilter);
}
function employeeExpiryTypeLabel(type) {
    return langData['expiry_' + type] || type;
}
function renderEmployeeExpiryCards(counts) {
    $('#empExpiryCardContract').text((Number(counts.contract) || 0).toLocaleString());
    $('#empExpiryCardWorkPermit').text((Number(counts.work_permit) || 0).toLocaleString());
    $('#empExpiryCardVisa').text((Number(counts.visa) || 0).toLocaleString());
    $('#empExpiryCardPassport').text((Number(counts.passport) || 0).toLocaleString());
}
function initEmployeeExpiryTable(items) {
    if ($.fn.DataTable.isDataTable('#tb_employee_expiry')) {
        tb_employee_expiry.clear().rows.add(items).draw();
        return;
    }
    tb_employee_expiry = $('#tb_employee_expiry').DataTable({
        data: items,
        responsive: true,
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        order: [[6, 'asc']],
        columns: [
            { data: 'employee_no', render: d => escapeHtmlList(d || '-') },
            { data: 'name', render: d => escapeHtmlList(d || '-') },
            { data: null, render: (d, t, row) => escapeHtmlList((currentLang === 'th' ? row.department_name_th : row.department_name_en) || '-') },
            { data: null, render: (d, t, row) => escapeHtmlList((currentLang === 'th' ? row.branch_name_th : row.branch_name_en) || '-') },
            {
                data: 'expiry_type',
                render: {
                    display: t => escapeHtmlList(employeeExpiryTypeLabel(t)),
                    sort: t => t,
                    filter: t => employeeExpiryTypeLabel(t),
                }
            },
            {
                data: 'expiry_date',
                render: {
                    display: d => formatDisplayDate(d),
                    sort: d => d,
                    filter: d => d,
                }
            },
            {
                data: 'days_remaining',
                render: {
                    display: d => {
                        const n = Number(d) || 0;
                        const cls = n < 0 ? 'text-danger fw-bold' : (n <= 30 ? 'text-warning fw-bold' : '');
                        const text = n < 0 ? `${Math.abs(n)} ${langData['expiry_days_overdue'] || 'days overdue'}` : `${n} ${langData['expiry_days_left'] || 'days left'}`;
                        return `<span class="${cls}">${text}</span>`;
                    },
                    sort: d => Number(d) || 0,
                    filter: d => Number(d) || 0,
                }
            },
        ],
    });
    if (typeof initExcelColumnFilters === 'function') {
        initExcelColumnFilters(tb_employee_expiry, { mode: 'client' });
    }
}
function loadEmployeeExpiryReport() {
    const filters = currentEmployeeExpiryFilters();
    $.ajax({
        url: `${BASE_URL}/api/employee.expiry-report`, method: 'POST', dataType: 'json',
        data: filters,
        success: function (res) {
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            renderEmployeeExpiryCards(res.data.counts);
            initEmployeeExpiryTable(res.data.items || []);
        }
    });
}
$(document).on('shown.bs.tab', '#empReportSub-expiry-tab', function () {
    loadEmployeeExpiryReport();
});
$(document).on('click', '#employeeExpiryStationFilterToggle', function () {
    const $filter = $('#employeeExpiryStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('change', '#employee_expiry_filter_within_days, #employee_expiry_filter_department, #employee_expiry_filter_branch', function () {
    updateClearEmployeeExpiryFilterVisibility();
    loadEmployeeExpiryReport();
});
$(document).on('click', '#btnClearEmployeeExpiryFilter', function () {
    $('#employee_expiry_filter_department, #employee_expiry_filter_branch').val(null).trigger('change.select2');
    updateClearEmployeeExpiryFilterVisibility();
    loadEmployeeExpiryReport();
});

/* ==================== Probation Status (2026-09-02, Phase 2) -- NO "days until due" column,
   confirmed via AskUserQuestion: no probation-period-length setting exists anywhere in this app
   yet, see EmployeeModel::probationReport()'s own docblock. ==================== */
let tb_employee_probation;
function currentEmployeeProbationFilters() {
    return {
        department_id: $('#employee_probation_filter_department').val() || '',
        branch_id: $('#employee_probation_filter_branch').val() || '',
    };
}
function updateClearEmployeeProbationFilterVisibility() {
    const f = currentEmployeeProbationFilters();
    const hasFilter = !!(f.department_id || f.branch_id);
    $('#employeeProbationFilterClearRow').toggleClass('d-none', !hasFilter);
}
function initEmployeeProbationTable(items) {
    if ($.fn.DataTable.isDataTable('#tb_employee_probation')) {
        tb_employee_probation.clear().rows.add(items).draw();
        return;
    }
    tb_employee_probation = $('#tb_employee_probation').DataTable({
        data: items,
        responsive: true,
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        order: [[5, 'desc']],
        columns: [
            { data: 'employee_no', render: d => escapeHtmlList(d || '-') },
            { data: 'name', render: d => escapeHtmlList(d || '-') },
            { data: null, render: (d, t, row) => escapeHtmlList((currentLang === 'th' ? row.department_name_th : row.department_name_en) || '-') },
            { data: null, render: (d, t, row) => escapeHtmlList((currentLang === 'th' ? row.branch_name_th : row.branch_name_en) || '-') },
            {
                data: 'employment_date',
                render: {
                    display: d => formatDisplayDate(d),
                    sort: d => d,
                    filter: d => d,
                }
            },
            { data: 'days_on_probation', className: 'text-end', render: d => (Number(d) || 0).toLocaleString() },
        ],
    });
    if (typeof initExcelColumnFilters === 'function') {
        initExcelColumnFilters(tb_employee_probation, { mode: 'client' });
    }
}
function loadEmployeeProbationReport() {
    const filters = currentEmployeeProbationFilters();
    $.ajax({
        url: `${BASE_URL}/api/employee.probation-report`, method: 'POST', dataType: 'json',
        data: filters,
        success: function (res) {
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            $('#empProbationCardCount').text((Number(res.data.count) || 0).toLocaleString());
            initEmployeeProbationTable(res.data.items || []);
        }
    });
}
$(document).on('shown.bs.tab', '#empReportSub-probation-tab', function () {
    loadEmployeeProbationReport();
});
$(document).on('click', '#employeeProbationStationFilterToggle', function () {
    const $filter = $('#employeeProbationStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('change', '#employee_probation_filter_department, #employee_probation_filter_branch', function () {
    updateClearEmployeeProbationFilterVisibility();
    loadEmployeeProbationReport();
});
$(document).on('click', '#btnClearEmployeeProbationFilter', function () {
    $('#employee_probation_filter_department, #employee_probation_filter_branch').val(null).trigger('change.select2');
    updateClearEmployeeProbationFilterVisibility();
    loadEmployeeProbationReport();
});

/* ==================== SSO/PVD Enrollment (2026-09-02, Phase 2) -- "which people," distinct from
   the existing statutory SSO 1-10 FORM exports. ==================== */
let tb_employee_enrollment;
let empEnrollmentSsoChartInstance = null;
let empEnrollmentPvdChartInstance = null;
function currentEmployeeEnrollmentFilters() {
    return {
        department_id: $('#employee_enrollment_filter_department').val() || '',
        branch_id: $('#employee_enrollment_filter_branch').val() || '',
    };
}
function updateClearEmployeeEnrollmentFilterVisibility() {
    const f = currentEmployeeEnrollmentFilters();
    const hasFilter = !!(f.department_id || f.branch_id);
    $('#employeeEnrollmentFilterClearRow').toggleClass('d-none', !hasFilter);
}
function renderEmployeeEnrollmentDonut(instanceGetter, instanceSetter, canvasId, enrolled, notEnrolled) {
    const $canvas = $(`#${canvasId}`);
    if (!$canvas.length || typeof Chart === 'undefined') return;
    let instance = instanceGetter();
    if (instance) {
        instance.data.datasets[0].data = [enrolled, notEnrolled];
        instance.update();
        return;
    }
    instance = new Chart($canvas[0].getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: [langData['enrollment_enrolled'] || 'Enrolled', langData['enrollment_not_enrolled'] || 'Not Enrolled'],
            datasets: [{ data: [enrolled, notEnrolled], backgroundColor: ['#198754', '#dc3545'], borderWidth: 2, borderColor: '#fff' }],
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: true, position: 'bottom' } } },
    });
    instanceSetter(instance);
}
function initEmployeeEnrollmentTable(items) {
    if ($.fn.DataTable.isDataTable('#tb_employee_enrollment')) {
        tb_employee_enrollment.clear().rows.add(items).draw();
        return;
    }
    const statusBadge = enrolled => Number(enrolled) === 1
        ? `<span class="badge bg-success-subtle text-success">${langData['enrollment_enrolled'] || 'Enrolled'}</span>`
        : `<span class="badge bg-secondary-subtle text-secondary">${langData['enrollment_not_enrolled'] || 'Not Enrolled'}</span>`;
    tb_employee_enrollment = $('#tb_employee_enrollment').DataTable({
        data: items,
        responsive: true,
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        columns: [
            { data: 'employee_no', render: d => escapeHtmlList(d || '-') },
            { data: 'name', render: d => escapeHtmlList(d || '-') },
            { data: null, render: (d, t, row) => escapeHtmlList((currentLang === 'th' ? row.department_name_th : row.department_name_en) || '-') },
            { data: null, render: (d, t, row) => escapeHtmlList((currentLang === 'th' ? row.branch_name_th : row.branch_name_en) || '-') },
            {
                data: 'sso_enrolled',
                render: {
                    display: d => statusBadge(d),
                    sort: d => Number(d) || 0,
                    filter: d => Number(d) === 1 ? (langData['enrollment_enrolled'] || 'Enrolled') : (langData['enrollment_not_enrolled'] || 'Not Enrolled'),
                }
            },
            {
                data: 'pvd_enrolled',
                render: {
                    display: d => statusBadge(d),
                    sort: d => Number(d) || 0,
                    filter: d => Number(d) === 1 ? (langData['enrollment_enrolled'] || 'Enrolled') : (langData['enrollment_not_enrolled'] || 'Not Enrolled'),
                }
            },
        ],
    });
    if (typeof initExcelColumnFilters === 'function') {
        initExcelColumnFilters(tb_employee_enrollment, { mode: 'client' });
    }
}
function loadEmployeeEnrollmentReport() {
    const filters = currentEmployeeEnrollmentFilters();
    $.ajax({
        url: `${BASE_URL}/api/employee.statutory-enrollment-report`, method: 'POST', dataType: 'json',
        data: filters,
        success: function (res) {
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            const c = res.data.counts;
            $('#empEnrollmentCardSsoEnrolled').text((Number(c.sso_enrolled) || 0).toLocaleString());
            $('#empEnrollmentCardSsoNotEnrolled').text((Number(c.sso_not_enrolled) || 0).toLocaleString());
            $('#empEnrollmentCardPvdEnrolled').text((Number(c.pvd_enrolled) || 0).toLocaleString());
            $('#empEnrollmentCardPvdNotEnrolled').text((Number(c.pvd_not_enrolled) || 0).toLocaleString());
            renderEmployeeEnrollmentDonut(() => empEnrollmentSsoChartInstance, v => { empEnrollmentSsoChartInstance = v; }, 'employeeEnrollmentSsoChart', c.sso_enrolled, c.sso_not_enrolled);
            renderEmployeeEnrollmentDonut(() => empEnrollmentPvdChartInstance, v => { empEnrollmentPvdChartInstance = v; }, 'employeeEnrollmentPvdChart', c.pvd_enrolled, c.pvd_not_enrolled);
            initEmployeeEnrollmentTable(res.data.items || []);
        }
    });
}
$(document).on('shown.bs.tab', '#empReportSub-enrollment-tab', function () {
    loadEmployeeEnrollmentReport();
});
$(document).on('click', '#employeeEnrollmentStationFilterToggle', function () {
    const $filter = $('#employeeEnrollmentStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('change', '#employee_enrollment_filter_department, #employee_enrollment_filter_branch', function () {
    updateClearEmployeeEnrollmentFilterVisibility();
    loadEmployeeEnrollmentReport();
});
$(document).on('click', '#btnClearEmployeeEnrollmentFilter', function () {
    $('#employee_enrollment_filter_department, #employee_enrollment_filter_branch').val(null).trigger('change.select2');
    updateClearEmployeeEnrollmentFilterVisibility();
    loadEmployeeEnrollmentReport();
});

/* ==================== Headcount Structure (2026-09-02, Phase 3) -- a current snapshot grouped by
   one dimension at a time. ==================== */
let tb_employee_structure;
let empStructureChartInstance = null;
function loadEmployeeStructureReport() {
    const groupBy = $('#employee_structure_filter_group_by').val() || 'department';
    $.ajax({
        url: `${BASE_URL}/api/employee.headcount-structure-report`, method: 'POST', dataType: 'json',
        data: { group_by: groupBy },
        success: function (res) {
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            const groups = res.data.groups || [];
            $('#empStructureCardTotal').text((Number(res.data.total) || 0).toLocaleString());
            $('#empStructureCardGroupCount').text(groups.length.toLocaleString());
            const largest = groups.length ? groups.reduce((a, b) => (b.count > a.count ? b : a)) : null;
            $('#empStructureCardLargest').text(largest ? `${(currentLang === 'th' ? largest.label_th : largest.label_en) || '-'} (${largest.count})` : '-');

            const labels = groups.map(g => (currentLang === 'th' ? g.label_th : g.label_en) || '-');
            const data = groups.map(g => Number(g.count) || 0);
            if (typeof Chart !== 'undefined' && $('#employeeStructureChart').length) {
                if (empStructureChartInstance) {
                    empStructureChartInstance.data.labels = labels;
                    empStructureChartInstance.data.datasets[0].data = data;
                    empStructureChartInstance.update();
                } else {
                    empStructureChartInstance = new Chart($('#employeeStructureChart')[0].getContext('2d'), {
                        type: 'bar',
                        data: { labels: labels, datasets: [{ data: data, backgroundColor: '#FF9900', borderRadius: 4, maxBarThickness: 40 }] },
                        options: {
                            responsive: true, maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
                        },
                    });
                }
            }

            const rows = groups.map(g => ({ label: (currentLang === 'th' ? g.label_th : g.label_en) || '-', count: g.count }));
            if ($.fn.DataTable.isDataTable('#tb_employee_structure')) {
                tb_employee_structure.clear().rows.add(rows).draw();
            } else {
                tb_employee_structure = $('#tb_employee_structure').DataTable({
                    data: rows,
                    responsive: true,
                    pageLength: pageLength,
                    lengthMenu: lengthMenu,
                    language: getTableLang(),
                    order: [[1, 'desc']],
                    columns: [
                        { data: 'label', render: d => escapeHtmlList(d || '-') },
                        { data: 'count', className: 'text-end', render: d => (Number(d) || 0).toLocaleString() },
                    ],
                });
                if (typeof initExcelColumnFilters === 'function') {
                    initExcelColumnFilters(tb_employee_structure, { mode: 'client' });
                }
            }
        }
    });
}
$(document).on('shown.bs.tab', '#empReportSub-structure-tab', function () {
    loadEmployeeStructureReport();
});
$(document).on('click', '#employeeStructureStationFilterToggle', function () {
    const $filter = $('#employeeStructureStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('change', '#employee_structure_filter_group_by', function () {
    loadEmployeeStructureReport();
});

/* ==================== Tenure / อายุงาน (2026-09-02, Phase 3) ==================== */
let tb_employee_tenure;
let empTenureChartInstance = null;
const EMP_TENURE_BUCKET_LABEL_KEYS = { '<1': 'tenure_bucket_under_1', '1-3': 'tenure_bucket_1_3', '3-5': 'tenure_bucket_3_5', '5-10': 'tenure_bucket_5_10', '10+': 'tenure_bucket_10_plus' };
function currentEmployeeTenureFilters() {
    return {
        department_id: $('#employee_tenure_filter_department').val() || '',
        branch_id: $('#employee_tenure_filter_branch').val() || '',
    };
}
function updateClearEmployeeTenureFilterVisibility() {
    const f = currentEmployeeTenureFilters();
    const hasFilter = !!(f.department_id || f.branch_id);
    $('#employeeTenureFilterClearRow').toggleClass('d-none', !hasFilter);
}
function initEmployeeTenureTable(items) {
    if ($.fn.DataTable.isDataTable('#tb_employee_tenure')) {
        tb_employee_tenure.clear().rows.add(items).draw();
        return;
    }
    tb_employee_tenure = $('#tb_employee_tenure').DataTable({
        data: items,
        responsive: true,
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        order: [[5, 'desc']],
        columns: [
            { data: 'employee_no', render: d => escapeHtmlList(d || '-') },
            { data: 'name', render: d => escapeHtmlList(d || '-') },
            { data: null, render: (d, t, row) => escapeHtmlList((currentLang === 'th' ? row.department_name_th : row.department_name_en) || '-') },
            { data: null, render: (d, t, row) => escapeHtmlList((currentLang === 'th' ? row.branch_name_th : row.branch_name_en) || '-') },
            {
                data: 'employment_date',
                render: {
                    display: d => formatDisplayDate(d),
                    sort: d => d,
                    filter: d => d,
                }
            },
            { data: 'tenure_years', className: 'text-end', render: d => (Number(d) || 0).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) },
        ],
    });
    if (typeof initExcelColumnFilters === 'function') {
        initExcelColumnFilters(tb_employee_tenure, { mode: 'client' });
    }
}
function loadEmployeeTenureReport() {
    const filters = currentEmployeeTenureFilters();
    $.ajax({
        url: `${BASE_URL}/api/employee.tenure-report`, method: 'POST', dataType: 'json',
        data: filters,
        success: function (res) {
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            const items = res.data.items || [];
            $('#empTenureCardTotal').text(items.length.toLocaleString());
            $('#empTenureCardAverage').text((Number(res.data.average_years) || 0).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' ' + (langData['years_unit'] || 'yrs'));
            $('#empTenureCardLongest').text((Number(res.data.longest_years) || 0).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' ' + (langData['years_unit'] || 'yrs'));

            const buckets = res.data.buckets || [];
            const labels = buckets.map(b => langData[EMP_TENURE_BUCKET_LABEL_KEYS[b.key]] || b.key);
            const data = buckets.map(b => Number(b.count) || 0);
            if (typeof Chart !== 'undefined' && $('#employeeTenureChart').length) {
                if (empTenureChartInstance) {
                    empTenureChartInstance.data.labels = labels;
                    empTenureChartInstance.data.datasets[0].data = data;
                    empTenureChartInstance.update();
                } else {
                    empTenureChartInstance = new Chart($('#employeeTenureChart')[0].getContext('2d'), {
                        type: 'bar',
                        data: { labels: labels, datasets: [{ data: data, backgroundColor: '#0dcaf0', borderRadius: 4, maxBarThickness: 48 }] },
                        options: {
                            responsive: true, maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
                        },
                    });
                }
            }
            initEmployeeTenureTable(items);
        }
    });
}
$(document).on('shown.bs.tab', '#empReportSub-tenure-tab', function () {
    loadEmployeeTenureReport();
});
$(document).on('click', '#employeeTenureStationFilterToggle', function () {
    const $filter = $('#employeeTenureStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('change', '#employee_tenure_filter_department, #employee_tenure_filter_branch', function () {
    updateClearEmployeeTenureFilterVisibility();
    loadEmployeeTenureReport();
});
$(document).on('click', '#btnClearEmployeeTenureFilter', function () {
    $('#employee_tenure_filter_department, #employee_tenure_filter_branch').val(null).trigger('change.select2');
    updateClearEmployeeTenureFilterVisibility();
    loadEmployeeTenureReport();
});

/* ==================== Birthday & Work Anniversary (2026-09-02, Phase 3) -- no chart, a simple
   monthly reminder list. ==================== */
let tb_employee_birthday;
let tb_employee_anniversary;
function currentEmployeeBirthdayFilters() {
    return {
        month: $('#employee_birthday_filter_month').val() || (new Date().getMonth() + 1),
        department_id: $('#employee_birthday_filter_department').val() || '',
        branch_id: $('#employee_birthday_filter_branch').val() || '',
    };
}
function updateClearEmployeeBirthdayFilterVisibility() {
    const f = currentEmployeeBirthdayFilters();
    const hasFilter = !!(f.department_id || f.branch_id);
    $('#employeeBirthdayFilterClearRow').toggleClass('d-none', !hasFilter);
}
function initEmployeeBirthdayTables(birthdays, anniversaries) {
    const deptCol = { data: null, render: (d, t, row) => escapeHtmlList((currentLang === 'th' ? row.department_name_th : row.department_name_en) || '-') };
    const dateCol = {
        data: 'event_date',
        render: {
            display: d => formatDisplayDate(d),
            sort: d => d,
            filter: d => d,
        }
    };
    if ($.fn.DataTable.isDataTable('#tb_employee_birthday')) {
        tb_employee_birthday.clear().rows.add(birthdays).draw();
    } else {
        tb_employee_birthday = $('#tb_employee_birthday').DataTable({
            data: birthdays,
            responsive: true,
            pageLength: pageLength,
            lengthMenu: lengthMenu,
            language: getTableLang(),
            columns: [
                { data: 'employee_no', render: d => escapeHtmlList(d || '-') },
                { data: 'name', render: d => escapeHtmlList(d || '-') },
                deptCol,
                dateCol,
            ],
        });
        if (typeof initExcelColumnFilters === 'function') {
            initExcelColumnFilters(tb_employee_birthday, { mode: 'client' });
        }
    }
    if ($.fn.DataTable.isDataTable('#tb_employee_anniversary')) {
        tb_employee_anniversary.clear().rows.add(anniversaries).draw();
    } else {
        tb_employee_anniversary = $('#tb_employee_anniversary').DataTable({
            data: anniversaries,
            responsive: true,
            pageLength: pageLength,
            lengthMenu: lengthMenu,
            language: getTableLang(),
            columns: [
                { data: 'employee_no', render: d => escapeHtmlList(d || '-') },
                { data: 'name', render: d => escapeHtmlList(d || '-') },
                deptCol,
                dateCol,
                { data: 'years', className: 'text-end', render: d => (Number(d) || 0).toLocaleString() },
            ],
        });
        if (typeof initExcelColumnFilters === 'function') {
            initExcelColumnFilters(tb_employee_anniversary, { mode: 'client' });
        }
    }
}
function loadEmployeeBirthdayReport() {
    const filters = currentEmployeeBirthdayFilters();
    $.ajax({
        url: `${BASE_URL}/api/employee.birthday-anniversary-report`, method: 'POST', dataType: 'json',
        data: filters,
        success: function (res) {
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            const birthdays = res.data.birthdays || [];
            const anniversaries = res.data.anniversaries || [];
            $('#empBirthdayCardCount').text(birthdays.length.toLocaleString());
            $('#empAnniversaryCardCount').text(anniversaries.length.toLocaleString());
            initEmployeeBirthdayTables(birthdays, anniversaries);
        }
    });
}
$(document).on('shown.bs.tab', '#empReportSub-birthday-tab', function () {
    loadEmployeeBirthdayReport();
});
$(document).on('click', '#employeeBirthdayStationFilterToggle', function () {
    const $filter = $('#employeeBirthdayStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('change', '#employee_birthday_filter_month, #employee_birthday_filter_department, #employee_birthday_filter_branch', function () {
    updateClearEmployeeBirthdayFilterVisibility();
    loadEmployeeBirthdayReport();
});
$(document).on('click', '#btnClearEmployeeBirthdayFilter', function () {
    $('#employee_birthday_filter_department, #employee_birthday_filter_branch').val(null).trigger('change.select2');
    updateClearEmployeeBirthdayFilterVisibility();
    loadEmployeeBirthdayReport();
});

/* ==================== Data Completeness overview (2026-09-02, Phase 4 -- the final phase) --
   aggregates the SAME per-employee % already shown on the List/Recheck tabs. ==================== */
let tb_employee_completeness;
let empCompletenessChartInstance = null;
const EMP_COMPLETENESS_BUCKET_LABEL_KEYS = { under_50: 'completeness_bucket_under_50', '50_80': 'completeness_bucket_50_80', '80_plus': 'completeness_bucket_80_plus' };
const EMP_COMPLETENESS_BUCKET_COLORS = { under_50: '#dc3545', '50_80': '#ffc107', '80_plus': '#198754' };
function currentEmployeeCompletenessFilters() {
    return {
        department_id: $('#employee_completeness_filter_department').val() || '',
        branch_id: $('#employee_completeness_filter_branch').val() || '',
    };
}
function updateClearEmployeeCompletenessFilterVisibility() {
    const f = currentEmployeeCompletenessFilters();
    const hasFilter = !!(f.department_id || f.branch_id);
    $('#employeeCompletenessFilterClearRow').toggleClass('d-none', !hasFilter);
}
function employeeCompletenessColor(percent) {
    if (percent >= 80) return '#198754';
    if (percent >= 50) return '#FF9900';
    return '#dc3545';
}
function initEmployeeCompletenessTable(items) {
    if ($.fn.DataTable.isDataTable('#tb_employee_completeness')) {
        tb_employee_completeness.clear().rows.add(items).draw();
        return;
    }
    tb_employee_completeness = $('#tb_employee_completeness').DataTable({
        data: items,
        responsive: true,
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        order: [[4, 'asc']],
        columns: [
            { data: 'employee_no', render: d => escapeHtmlList(d || '-') },
            { data: 'name', render: d => escapeHtmlList(d || '-') },
            { data: null, render: (d, t, row) => escapeHtmlList((currentLang === 'th' ? row.department_name_th : row.department_name_en) || '-') },
            { data: null, render: (d, t, row) => escapeHtmlList((currentLang === 'th' ? row.branch_name_th : row.branch_name_en) || '-') },
            {
                data: 'completeness',
                className: 'text-end',
                render: {
                    display: d => `<span class="fw-semibold" style="color:${employeeCompletenessColor(Number(d) || 0)};">${Number(d) || 0}%</span>`,
                    sort: d => Number(d) || 0,
                    filter: d => Number(d) || 0,
                }
            },
        ],
    });
    if (typeof initExcelColumnFilters === 'function') {
        initExcelColumnFilters(tb_employee_completeness, { mode: 'client' });
    }
}
function loadEmployeeCompletenessReport() {
    const filters = currentEmployeeCompletenessFilters();
    $.ajax({
        url: `${BASE_URL}/api/employee.completeness-overview-report`, method: 'POST', dataType: 'json',
        data: filters,
        success: function (res) {
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            const items = res.data.items || [];
            const buckets = res.data.buckets || [];
            $('#empCompletenessCardTotal').text(items.length.toLocaleString());
            $('#empCompletenessCardAverage').text((Number(res.data.average_percent) || 0).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%');
            const underAttention = buckets.find(b => b.key === 'under_50');
            $('#empCompletenessCardNeedsAttention').text(((underAttention && underAttention.count) || 0).toLocaleString());

            const labels = buckets.map(b => langData[EMP_COMPLETENESS_BUCKET_LABEL_KEYS[b.key]] || b.key);
            const data = buckets.map(b => Number(b.count) || 0);
            const colors = buckets.map(b => EMP_COMPLETENESS_BUCKET_COLORS[b.key] || '#6c757d');
            if (typeof Chart !== 'undefined' && $('#employeeCompletenessChart').length) {
                if (empCompletenessChartInstance) {
                    empCompletenessChartInstance.data.labels = labels;
                    empCompletenessChartInstance.data.datasets[0].data = data;
                    empCompletenessChartInstance.data.datasets[0].backgroundColor = colors;
                    empCompletenessChartInstance.update();
                } else {
                    empCompletenessChartInstance = new Chart($('#employeeCompletenessChart')[0].getContext('2d'), {
                        type: 'bar',
                        data: { labels: labels, datasets: [{ data: data, backgroundColor: colors, borderRadius: 4, maxBarThickness: 60 }] },
                        options: {
                            responsive: true, maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
                        },
                    });
                }
            }
            initEmployeeCompletenessTable(items);
        }
    });
}
$(document).on('shown.bs.tab', '#empReportSub-completeness-tab', function () {
    loadEmployeeCompletenessReport();
});
$(document).on('click', '#employeeCompletenessStationFilterToggle', function () {
    const $filter = $('#employeeCompletenessStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('change', '#employee_completeness_filter_department, #employee_completeness_filter_branch', function () {
    updateClearEmployeeCompletenessFilterVisibility();
    loadEmployeeCompletenessReport();
});
$(document).on('click', '#btnClearEmployeeCompletenessFilter', function () {
    $('#employee_completeness_filter_department, #employee_completeness_filter_branch').val(null).trigger('change.select2');
    updateClearEmployeeCompletenessFilterVisibility();
    loadEmployeeCompletenessReport();
});

