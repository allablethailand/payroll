let reportList = [];
let cycleRuns = [];

/**
 * 2026-08-27, explicit request: "ปรับ Design และโครงสร้างให้หน่อยครับ...แบ่งเป็น Report ที่ต้องดึงจาก
 * รอบการจ่าย และ Report ประจำปีที่ต้องออก" -- Phase 1 of 2 (see app/views/reports/index.php's own
 * comment for the full context/Phase 2 note). `frequency` drives which tab a report belongs to
 * (`cycle` -> the Per-Cycle Reports matrix table, one column per report; `annual` -> the Annual
 * Reports tab's shared-year-picker + card layout). `extra` lists only the fields a report needs
 * BEYOND the run/year it's already scoped to by its row/picker -- for `cycle` reports the ONLY
 * value ever used is `employee` (Pay Slip: there's no run-level version of a pay slip, so its
 * matrix cell opens an employee-picker modal instead of exporting directly); for `annual` reports
 * SSO 6-09 needs its own month on top of the shared year, Payment Voucher needs its own employee.
 * New report = one line here, same "new report type = one entry" convention the old
 * REPORT_CONTEXT_FIELDS map already had.
 *
 * 2026-08-29: `frequency:'cycle'` entries are now used ONLY to keep this list documented/in sync
 * with ReportsController::CYCLE_REPORT_CODES (server-side) -- the Per-Cycle Reports tab itself was
 * rebuilt to fetch its rows from runCycleReportsSummary() instead of filtering reportList by this
 * map (see that tab's own section further down), so `extra` is effectively dead for 'cycle' entries
 * now (the server's own `per_employee` flag on each row replaced it) but kept accurate as
 * documentation. Still load-bearing for 'annual' entries (annualReportRows()/the
 * .btn-annual-report-download click handler further down still read `frequency`/`extra` from
 * here -- 2026-08-30: that section was itself rebuilt from cards to a table, see its own header
 * comment, but still keys off this same map).
 */
const REPORT_META = {
    TH_PND1: { frequency: 'cycle', extra: [] },
    TH_SSO110: { frequency: 'cycle', extra: [] },
    TH_SLF: { frequency: 'cycle', extra: [] },
    PAY_SLIP: { frequency: 'cycle', extra: ['employee'] },
    BANK_TRANSFER_FILE: { frequency: 'cycle', extra: [] },
    PAYROLL_REGISTER: { frequency: 'cycle', extra: [] },
    TH_PND1K_SUMMARY: { frequency: 'annual', extra: [] },
    TH_KOR20KOR: { frequency: 'annual', extra: [] },
    TH_SSO609: { frequency: 'annual', extra: ['month'] },
    PAYMENT_VOUCHER: { frequency: 'annual', extra: ['employee'] },
};

function toIsoDateReports(displayVal) {
    if (!displayVal) return '';
    const parts = String(displayVal).split('/');
    if (parts.length !== 3) return displayVal;
    const [dd, mm, yyyy] = parts;
    return `${yyyy}-${mm.padStart(2, '0')}-${dd.padStart(2, '0')}`;
}
function reportLabel(report) {
    return (currentLang === 'th' ? report.label.th : report.label.en) || report.label.th || report.label.en || report.code;
}
function formatLabel(fmt) {
    const key = 'format_' + fmt;
    return langData[key] || fmt.toUpperCase();
}

/* ---------- Annual Reports tab (2026-08-30 rebuild): a row-list table, SAME pattern as Per-Cycle
   Reports -- Report | Downloads | Last Downloaded | Actions, now with a Report-type icon per row
   (see annualReportActionsHtml()/REPORT_TYPE_ICONS) and a History button alongside Download (see
   #annualReportHistoryModal further down). A report needing extra input before it can preview
   (format always; SSO 6-09 also needs month; Payment Voucher also needs employee) opens
   #reportsPreviewModal directly now (2026-08-30, same-day follow-up: "ไม่ต้อง 2 step") -- see
   openReportsPreview()'s own docblock for the `options` param that drives its footer controls. ---------- */
let annualReportCounts = {}; // report_code => {download_count, last_downloaded_at}, for the currently selected year
let tb_annual_reports;

function loadReportList() {
    $.ajax({
        url: `${BASE_URL}/api/report.list`,
        method: 'GET',
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                reportList = res.data;
                renderAnnualReportsTable();
            }
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while loading the data.');
        }
    });
}
function annualReportRows() {
    return reportList.filter(function (r) {
        const meta = REPORT_META[r.code] || { frequency: 'annual' };
        return meta.frequency === 'annual';
    });
}
function annualReportActionsHtml(report) {
    return `<div class="btn-group border rounded-3 bg-white">
        <button type="button" class="btn btn-link text-primary btn-annual-report-download" data-code="${report.code}" title="${langData['report_preview_and_download'] || 'Preview & Download'}"><i class="fa-solid fa-download"></i></button>
        <button type="button" class="btn btn-link text-secondary border-start btn-annual-report-history" data-code="${report.code}" title="${langData['report_view_history'] || 'View Download History'}"><i class="fa-solid fa-clock-rotate-left"></i></button>
    </div>`;
}
function renderAnnualReportsTable() {
    const rows = annualReportRows();
    $('#noReports_annual').toggleClass('d-none', rows.length > 0);
    $('#tb_annual_reports').closest('.table-responsive').toggleClass('d-none', rows.length === 0);
    if ($.fn.DataTable.isDataTable('#tb_annual_reports')) {
        tb_annual_reports.destroy();
        $('#tb_annual_reports').find('tbody').empty();
    }
    if (rows.length === 0) return;
    tb_annual_reports = $('#tb_annual_reports').DataTable({
        data: rows,
        responsive: true,
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        columns: [
            // 2026-08-30, explicit request: "ในตารางอยากให้เพิ่ม icon ของรายงานแต่ละตัว ตอนนี้ตารางดูโล้นๆ" --
            // reuses the SAME .reports-row-report-type-icon/.rt-* classes (this file's own <style>
            // block in reports/index.php) and REPORT_TYPE_ICONS map Per-Cycle Reports' own rows
            // already use, keyed off the same report_type value every row already carries.
            { data: null, render: (d, t, report) => `<span class="reports-row-report-type-icon rt-${report.report_type}"><i class="fa-solid ${REPORT_TYPE_ICONS[report.report_type] || 'fa-file-lines'}"></i></span><span class="reports-row-report-name">${escapeHtmlReports(reportLabel(report))}</span>` },
            { data: null, className: 'text-center', render: (d, t, report) => (annualReportCounts[report.code] || {}).download_count || 0 },
            { data: null, render: (d, t, report) => {
                const c = annualReportCounts[report.code];
                return c && c.last_downloaded_at ? formatDisplayDateTime(c.last_downloaded_at) : `<span class="text-muted">${langData['report_never_downloaded'] || 'Never'}</span>`;
            } },
            { data: null, className: 'text-center all', orderable: false, render: (d, t, report) => annualReportActionsHtml(report) },
        ],
        drawCallback: function () { getTableLang(); }
    });
}
function loadAnnualReportCounts(year) {
    $.getJSON(`${BASE_URL}/api/report.annual-summary`, { year: year }, function (res) {
        annualReportCounts = (res.status && res.data) ? res.data : {};
        if ($.fn.DataTable.isDataTable('#tb_annual_reports')) tb_annual_reports.draw(false);
    });
}

// 2026-08-30, explicit request: "ตอนกด Download ให้ขึ้นมา modal เดียวเลย...ไม่ต้อง 2 step" -- the old
// #annualReportConfigModal 2-step flow (collect format/month/employee, THEN open the preview) is
// gone; the Download button opens #reportsPreviewModal directly with sensible initial values
// (first supported format, no month/employee preselected), and the modal's own footer controls
// (added this round, see modals.php's own comment) let those be changed WITHOUT closing/reopening
// anything -- openReportsPreview()'s new 3rd `options` param drives which controls show.
$(document).on('click', '.btn-annual-report-download', function () {
    const report = annualReportRows().find(r => r.code === $(this).data('code'));
    if (!report) return;
    const year = $('#reportsPeriodYear').val();
    if (!year) {
        showWarning(langData['select_year_first'] || 'Please select a year first.');
        return;
    }
    const meta = REPORT_META[report.code] || { frequency: 'annual', extra: [] };
    const params = new URLSearchParams();
    params.set('report_code', report.code);
    params.set('format', report.supported_formats[0]);
    params.set('year', year);
    if (meta.extra.includes('month')) params.set('month', '1');
    openReportsPreview(params, reportLabel(report), {
        formatOptions: report.supported_formats,
        extra: { month: meta.extra.includes('month'), employee: meta.extra.includes('employee') },
    });
});

// generateReport(url) moved to public/js/app.js (2026-08-29) -- loaded on every page now so the
// Payroll Process List/Detail pages' own report shortcut buttons can reuse it too, not just this
// page. See app.js for the implementation (unchanged).

// 2026-08-29, explicit request: "ตอนกด Download ก็ให้เป็น Preview ก่อนค่อยกดเหมือนกัน" -- shared by all 3
// generation entry points on this page (Annual Reports form above, Per-Cycle matrix cell + its
// employee-picker below). `params` must NOT include `preview` -- this function adds it itself for
// the iframe src only, leaving the caller's own params clean for the modal's own TH/EN download
// buttons to clone and reuse. Mirrors Payroll Process Detail's #reportPreviewModal (detail.js) --
// same stale-iframe-'load'-handler bug already found and fixed there, avoided here the same way
// (unbind unconditionally, on every open, before branching on supportsPreview).
let reportsPreviewParams = null;
let reportsPreviewOptions = {};
/**
 * @param params URLSearchParams -- the FIXED context (report_code, run_id or year, and an initial
 *   format/month if the report needs them) -- NOT including `preview`, added internally for the
 *   iframe src only.
 * @param label string -- modal title.
 * @param options {formatOptions?: string[], extra?: {month?: bool, employee?: bool}} -- 2026-08-30,
 *   drives the footer's format/month/employee controls (see modals.php's own comment on this modal).
 *   Omit entirely for a report with a fixed format and no extra fields (Per-Cycle Reports/Pay Slip
 *   roster -- their own call sites are unchanged, this param defaulting to {} is exactly their old
 *   behavior).
 */
function openReportsPreview(params, label, options = {}) {
    reportsPreviewParams = params;
    reportsPreviewOptions = options;
    $('#reportsPreviewModalTitle').text(label);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('reportsPreviewModal')).show();

    const formatOptions = options.formatOptions || null;
    $('#reportsPreviewFormatWrap').toggleClass('d-none', !formatOptions || formatOptions.length < 2);
    if (formatOptions && formatOptions.length >= 2) {
        const $fmt = $('#reportsPreviewFormatSelect');
        $fmt.empty();
        formatOptions.forEach(f => $fmt.append(new Option(formatLabel(f), f, f === params.get('format'), f === params.get('format'))));
    }
    const extra = options.extra || {};
    $('#reportsPreviewMonthWrap').toggleClass('d-none', !extra.month);
    if (extra.month && typeof initSelect2 === 'function') {
        initSelect2('#reportsPreviewMonthSelect', { mode: 'static' });
        $('#reportsPreviewMonthSelect').val(params.get('month') || '1').trigger('change.select2');
    }
    $('#reportsPreviewEmployeeWrap').toggleClass('d-none', !extra.employee);
    if (extra.employee && typeof initSelect2 === 'function') {
        initSelect2('#reportsPreviewEmployeeSelect', { mode: 'ajax', allowClear: true });
        $('#reportsPreviewEmployeeSelect').val(null).trigger('change.select2');
    }

    renderReportsPreviewFrame();
}
function currentReportsPreviewParams() {
    const params = new URLSearchParams(reportsPreviewParams);
    const options = reportsPreviewOptions;
    if (options.formatOptions && options.formatOptions.length >= 2) {
        params.set('format', $('#reportsPreviewFormatSelect').val() || params.get('format'));
    }
    if (options.extra && options.extra.month) {
        params.set('month', $('#reportsPreviewMonthSelect').val() || '1');
    }
    if (options.extra && options.extra.employee) {
        const empId = $('#reportsPreviewEmployeeSelect').val();
        if (empId) { params.set('employee_id', empId); } else { params.delete('employee_id'); }
    }
    return params;
}
function renderReportsPreviewFrame() {
    const params = currentReportsPreviewParams();
    const $frame = $('#reportsPreviewFrame').off('load').addClass('d-none').attr('src', '');
    const $loading = $('#reportsPreviewLoading').addClass('d-none');
    const $unavailable = $('#reportsPreviewUnavailable').addClass('d-none');
    const $selectEmployee = $('#reportsPreviewSelectEmployee').addClass('d-none');

    // Payment Voucher (the only report with extra.employee) needs an employee picked before there's
    // anything to preview at all -- show a plain hint instead of firing a request that would just
    // 400 server-side for a missing employee_id.
    if (reportsPreviewOptions.extra && reportsPreviewOptions.extra.employee && !params.get('employee_id')) {
        $('#reportsPreviewDialog').removeClass('modal-xl');
        $selectEmployee.removeClass('d-none');
        return;
    }

    const supportsPreview = params.get('format') === 'pdf';
    $('#reportsPreviewDialog').toggleClass('modal-xl', supportsPreview);
    if (!supportsPreview) {
        $unavailable.removeClass('d-none');
        return;
    }
    $loading.removeClass('d-none');
    const previewParams = new URLSearchParams(params);
    previewParams.set('preview', '1');
    $frame.on('load', function () {
        $loading.addClass('d-none');
        $frame.removeClass('d-none');
    });
    $frame.attr('src', `${BASE_URL}/api/report.generate?${previewParams.toString()}`);
}
$(document).on('change', '#reportsPreviewFormatSelect, #reportsPreviewMonthSelect, #reportsPreviewEmployeeSelect', function () {
    renderReportsPreviewFrame();
});
$(document).on('click', '.reports-preview-download-btn', function () {
    if (!reportsPreviewParams) return;
    if (reportsPreviewOptions.extra && reportsPreviewOptions.extra.employee && !$('#reportsPreviewEmployeeSelect').val()) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const dlParams = currentReportsPreviewParams();
    dlParams.set('language', $(this).data('language'));
    generateReport(`${BASE_URL}/api/report.generate?${dlParams.toString()}`, function () {
        if (tb_export_history) tb_export_history.ajax.reload(null, false);
        // 2026-08-30: covers Annual Reports' own Downloads/Last Downloaded columns too -- cheap
        // no-op if the download wasn't for an annual report (or the year field is empty).
        const year = $('#reportsPeriodYear').val();
        if (year) loadAnnualReportCounts(year);
    });
});

/* ---------- Per-Cycle Reports tab (2026-08-29 rebuild): pick ONE run, then a row-list per report
   type -- SAME pattern as Payroll Process Detail's own "Reports" tab (Report | Downloads | Last
   Downloaded | Actions), reusing openReportsPreview() above for the actual Download action. See
   this tab's own markup comment in reports/index.php for the full "ใช้หลักการเดียวกับหน้า Process"
   rationale. */

function escapeHtmlReports(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}

const REPORT_TYPE_ICONS = { statutory: 'fa-landmark', payment: 'fa-money-check-dollar', internal: 'fa-building' };

let cycleReportRows = []; // flat, current selected run's own rows (from runCycleReportsSummary())
let selectedCycleRunId = null;
const cycleTypesInited = { statutory: false, payment: false, internal: false };

function runOptionLabel(run) {
    const start = (typeof formatDisplayDate === 'function') ? formatDisplayDate(run.period_start_date) : run.period_start_date;
    const end = (typeof formatDisplayDate === 'function') ? formatDisplayDate(run.period_end_date) : run.period_end_date;
    return `${run.run_name || run.cycle_name || '-'} (${start} - ${end})`;
}

function loadCycleRuns() {
    $.ajax({
        url: `${BASE_URL}/api/report.cycle-runs`,
        method: 'GET',
        dataType: 'json',
        success: function (res) {
            if (!res.status) return;
            cycleRuns = res.data || [];
            const $select = $('#cycleReportRunSelect').empty();
            cycleRuns.forEach(function (run) {
                $select.append(`<option value="${run.id}">${escapeHtmlReports(runOptionLabel(run))}</option>`);
            });
            $('#cycleReportsNoRunBanner').toggleClass('d-none', cycleRuns.length > 0);
            $('#cycleReportBody').toggleClass('d-none', cycleRuns.length === 0);
            $('#cycleReportPeriodBar').toggleClass('d-none', cycleRuns.length === 0);
            if (cycleRuns.length > 0) {
                // #cycleReportRunSelect (.select2-native) is already select2-initialized by
                // app.js's own global page-load pass (empty at that point, since this fetch is
                // async) -- appending real <option> elements to the underlying native <select>
                // then triggering 'change' is Select2's own standard way to refresh an ALREADY-
                // initialized widget's option list (distinct from this app's documented
                // select2-remote-empty-preload gotcha, which is specifically about setting a VALUE
                // with no matching <option> present -- here the options themselves are what's
                // being added, so no re-init is needed or attempted). The browser auto-selects the
                // FIRST appended option (the newest run, cycleRuns[0]) since none carries a
                // `selected` attribute -- this trigger alone is what loads its summary, via the
                // #cycleReportRunSelect change handler further down; no separate explicit call
                // needed (would otherwise double-fetch on first load).
                $select.trigger('change');
            }
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while loading the data.');
        }
    });
}

// 2026-08-30, explicit request: "filter ปีให้เลือกจากปีที่มีข้อมูลจริง" -- was a free-typed number
// input defaulting to the current B.E. year regardless of whether any data actually existed for it;
// now a dropdown of only the years ReportsController::availableYears() confirms have at least one
// usable-state run, defaulting to the most recent one.
function loadAvailableYears() {
    $.getJSON(`${BASE_URL}/api/report.available-years`, function (res) {
        if (!res.status) return;
        const years = res.data || [];
        const $select = $('#reportsPeriodYear').empty();
        years.forEach(function (y) {
            $select.append(`<option value="${y}">${y}</option>`);
        });
        $('#annualReportsNoYearBanner').toggleClass('d-none', years.length > 0);
        $('#annualReportBody').toggleClass('d-none', years.length === 0);
        $('#annualReportPeriodBar').toggleClass('d-none', years.length === 0);
        if (years.length > 0) {
            $select.trigger('change'); // same select2-native-already-initialized refresh pattern as loadCycleRuns() above
        }
    });
}
$(document).on('change', '#reportsPeriodYear', function () {
    const year = $(this).val();
    if (year) loadAnnualReportCounts(year);
});
$(document).on('click', '#cycleReportPeriodBarToggle', function () {
    const $filter = $('#cycleReportPeriodBar').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('click', '#annualReportPeriodBarToggle', function () {
    const $filter = $('#annualReportPeriodBar').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});

function loadCycleReportSummary(runId) {
    selectedCycleRunId = runId;
    $.getJSON(`${BASE_URL}/api/report.run-cycle-summary`, { run_id: runId }, function (res) {
        if (!res.status) return;
        cycleReportRows = res.data || [];
        cycleTypesInited.statutory = false;
        cycleTypesInited.payment = false;
        cycleTypesInited.internal = false;
        ['statutory', 'payment', 'internal'].forEach(function (type) {
            if ($.fn.DataTable.isDataTable(`#tb_cycle_${type}`)) {
                $(`#tb_cycle_${type}`).DataTable().destroy();
                $(`#tb_cycle_${type}`).find('tbody').empty();
            }
        });
        const activeType = $('#cycleReportTypeTabs button.active').data('report-type') || 'statutory';
        ensureCycleTypeInited(activeType);
    });
}

function cycleReportActionsHtml(row) {
    const disabledAttr = ''; // every offered run is already approved/paid/locked -- cycleRuns() itself only lists those states
    const downloadTitle = row.per_employee ? (langData['select_employee_to_download'] || 'Select an employee to download') : (langData['report_preview_and_download'] || 'Preview & Download');
    return `<div class="btn-group border rounded-3 bg-white">
        <button type="button" class="btn btn-link text-primary btn-cycle-report-download" data-code="${row.code}" ${disabledAttr} title="${downloadTitle}"><i class="fa-solid fa-download"></i></button>
        <button type="button" class="btn btn-link text-secondary border-start btn-cycle-report-history" data-code="${row.code}" title="${langData['report_view_history'] || 'View Download History'}"><i class="fa-solid fa-clock-rotate-left"></i></button>
    </div>`;
}

function renderCycleReportTable(type) {
    const rows = cycleReportRows.filter(r => r.report_type === type);
    const $table = $(`#tb_cycle_${type}`);
    const hasData = rows.length > 0;
    $table.closest('.table-responsive').toggleClass('d-none', !hasData);
    const $empty = $(`#noCycleReports_${type}`).toggleClass('d-none', hasData);
    if (!hasData) {
        $empty.find('span').text(langData['no_reports_available'] || 'No reports are registered in this category yet.');
        return;
    }
    $table.DataTable({
        data: rows,
        responsive: true,
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        columns: [
            // object-form render: sort-safety (this app's own audited convention, see CLAUDE.md) --
            // sort/filter key off the plain report label / raw ISO timestamp, not the icon-prefixed
            // HTML or the dd/mm/yyyy display string.
            { data: null, render: { display: (d, t, row) => `<span class="reports-row-report-type-icon rt-${type}"><i class="fa-solid ${REPORT_TYPE_ICONS[type]}"></i></span><span class="reports-row-report-name">${escapeHtmlReports(reportLabel(row))}</span>`, sort: (d, t, row) => reportLabel(row), filter: (d, t, row) => reportLabel(row) } },
            { data: 'download_count', className: 'text-center' },
            { data: 'last_downloaded_at', render: { display: (v) => v ? formatDisplayDateTime(v) : `<span class="text-muted">${langData['report_never_downloaded'] || 'Never'}</span>`, sort: (v) => v || '', filter: (v) => v || '' } },
            { data: null, className: 'text-center all', orderable: false, render: (d, t, row) => cycleReportActionsHtml(row) },
        ],
        drawCallback: function () { getTableLang(); }
    });
}

/** Guards against the "DataTable(responsive:true) initialized while its Bootstrap tab pane is
 *  display:none collapses every column to 0 width" bug this app has hit repeatedly elsewhere. */
function ensureCycleTypeInited(type) {
    if (cycleTypesInited[type]) return;
    if (!$('#cycle-pane').hasClass('active')) return;
    renderCycleReportTable(type);
    cycleTypesInited[type] = true;
}

$(document).on('shown.bs.tab', '#cycleReportTypeTabs button', function () {
    ensureCycleTypeInited($(this).data('report-type'));
});
$(document).on('change', '#cycleReportRunSelect', function () {
    const runId = $(this).val();
    if (runId) loadCycleReportSummary(runId);
});

/** A report's Download button either opens the shared preview modal directly (openReportsPreview(),
 *  same as every other page here), or -- for a per-employee report (Pay Slip) -- opens the roster
 *  picker instead, since there's no single "the run's own" file to preview/download. */
$(document).on('click', '.btn-cycle-report-download', function () {
    const row = cycleReportRows.find(r => r.code === $(this).data('code'));
    if (!row || !selectedCycleRunId) return;
    if (row.per_employee) {
        openPayslipRoster(row, selectedCycleRunId);
        return;
    }
    const params = new URLSearchParams();
    params.set('report_code', row.code);
    params.set('format', row.format);
    params.set('run_id', selectedCycleRunId);
    openReportsPreview(params, reportLabel(row));
});

/* ---------- Pay Slip roster picker (2026-08-29, explicit request: "ปรับให้ขึ้นเป็นรายชื่อพนักงานมาเลย และ
   emp code ด้วย แผนกตำแหน่งทีม และมีปุ่มให้กด Download และแสดงด้วยว่า Download ไปแล้วกี่ครั้ง") ---------- */
let tb_payslip_roster;
function employeeNameReports(row) {
    if (currentLang === 'th') return `${row.name_th || ''} ${row.surname_th || ''}`.trim() || row.name_en || '-';
    return `${row.name_en || ''} ${row.surname_en || ''}`.trim() || row.name_th || '-';
}
function openPayslipRoster(row, runId) {
    $('#payslipRosterModalTitle').text(reportLabel(row));
    bootstrap.Modal.getOrCreateInstance(document.getElementById('payslipRosterModal')).show();
    if ($.fn.DataTable.isDataTable('#tb_payslip_roster')) {
        tb_payslip_roster.destroy();
        $('#tb_payslip_roster').find('tbody').empty();
    }
    tb_payslip_roster = $('#tb_payslip_roster').DataTable({
        responsive: true,
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        ajax: {
            url: `${BASE_URL}/api/report.payslip-roster`,
            dataSrc: 'data',
            data: function (d) { d.run_id = runId; d.report_code = row.code; }
        },
        columns: [
            { data: 'employee_no' },
            { data: null, render: (d, t, r) => escapeHtmlReports(employeeNameReports(r)) },
            { data: null, render: (d, t, r) => escapeHtmlReports((currentLang === 'th' ? r.department_name_th : r.department_name_en) || r.department_name_th || '-') },
            { data: null, render: (d, t, r) => escapeHtmlReports((currentLang === 'th' ? r.position_name_th : r.position_name_en) || r.position_name_th || '-') },
            { data: null, render: (d, t, r) => escapeHtmlReports((currentLang === 'th' ? r.team_name_th : r.team_name_en) || r.team_name_th || '-') },
            { data: 'download_count', className: 'text-center' },
            {
                data: null, className: 'text-center all', orderable: false,
                render: (d, t, r) => `<button type="button" class="btn btn-sm btn-outline-primary btn-payslip-roster-download" data-employee-id="${r.employee_id}"><i class="fa-solid fa-download"></i></button>`
            },
        ],
        initComplete: function () {
            initExcelColumnFilters(this.api(), {
                mode: 'client',
                columns: [
                    { index: 0, key: 'roster_employee_no' },
                    { index: 2, key: 'roster_department' },
                    { index: 3, key: 'roster_position' },
                    { index: 4, key: 'roster_team' },
                ]
            });
        },
        drawCallback: function () { getTableLang(); }
    });
}
$(document).on('click', '.btn-payslip-roster-download', function () {
    const employeeId = $(this).data('employee-id');
    const params = new URLSearchParams();
    params.set('report_code', 'PAY_SLIP');
    params.set('format', 'pdf');
    params.set('run_id', selectedCycleRunId);
    params.set('employee_id', employeeId);
    const report = reportList.find(r => r.code === 'PAY_SLIP');
    openReportsPreview(params, report ? reportLabel(report) : 'Pay Slip');
});
// A Pay Slip download from inside the roster modal should refresh that SAME employee's own count
// once the modal is reopened -- simplest correct approach is just reloading the roster table
// whenever a download succeeds while it's the one currently showing (generateReport()'s own
// onSuccess callback, wired inside openReportsPreview()'s download buttons -- see that shared
// handler above; it already reloads tb_export_history, this adds the roster too).
$(document).on('click', '.reports-preview-download-btn', function () {
    if (tb_payslip_roster && $('#payslipRosterModal').hasClass('show')) {
        setTimeout(function () { tb_payslip_roster.ajax.reload(null, false); }, 400);
    }
});

/* ---------- Per-report download history (2026-08-29, generalized 2026-08-30 to also serve Annual
   Reports' own new History button -- explicit request: "และมีปุ่มดูประวัติการ Download ด้วย พร้อมทั้ง
   filter") -- SAME pattern as Payroll Process Detail's own #reportHistoryModal (station-filter date
   range + Excel-column-filter, see that page's own detail.js docblock for the full reasoning),
   scoped to ONE report_code plus EITHER the currently selected cycle run OR the currently selected
   annual year (reportHistoryScopeParams below, merged into the ajax request -- ReportExportLogModel::
   list() already supports both `payroll_run_id` and `period_year` as independent filters, so no
   backend change was needed for this). Reuses the SAME #cycleReportHistoryModal/#tb_cycle_report_history
   markup for both -- the modal itself has nothing cycle-specific about its shape, just its own id
   from before Annual had an equivalent, kept unrenamed to avoid an unrelated markup churn. ---------- */
let dtCycleReportHistory = null;
let cycleReportHistoryCode = null;
let reportHistoryScopeParams = {};
function rdReportByLabelReports(l) {
    return (currentLang === 'th' ? l.generated_by_name_th : l.generated_by_name_en) || l.generated_by_name_th || l.generated_by_name_en || '-';
}
function rdReportLanguageLabelReports(l) {
    if (l.language === 'en') return langData['language_en'] || 'English';
    if (l.language === 'th') return langData['language_th'] || 'Thai';
    return '-';
}
function rdReportDeviceLabelReports(l) {
    if (!l.device_type) return '-';
    return l.os_name ? `${l.device_type} (${l.os_name})` : l.device_type;
}
function rdReportBrowserLabelReports(l) {
    if (!l.browser_name) return '-';
    return l.browser_version ? `${l.browser_name} ${l.browser_version}` : l.browser_name;
}
function reloadCycleReportHistoryTable() {
    if (dtCycleReportHistory) dtCycleReportHistory.ajax.reload(null, false);
}
function openReportHistoryModal(reportCode, label, scopeParams) {
    cycleReportHistoryCode = reportCode;
    reportHistoryScopeParams = scopeParams;
    $('#cycleReportHistoryModalTitle').text(`${langData['report_view_history'] || 'View Download History'} - ${label}`);
    $('#cycleReportHistoryDateFrom, #cycleReportHistoryDateTo').val('');
    if (typeof initDatepicker === 'function') {
        initDatepicker('#cycleReportHistoryDateFrom');
        initDatepicker('#cycleReportHistoryDateTo');
    }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('cycleReportHistoryModal')).show();
    if (dtCycleReportHistory) { dtCycleReportHistory.destroy(); dtCycleReportHistory = null; }
    dtCycleReportHistory = $('#tb_cycle_report_history').DataTable({
        responsive: true,
        order: [[0, 'desc']],
        ajax: {
            url: `${BASE_URL}/api/report.export-logs`,
            dataSrc: 'data',
            data: function (d) {
                d.report_code = cycleReportHistoryCode;
                Object.assign(d, reportHistoryScopeParams);
                d.date_from = toIsoDateReports($('#cycleReportHistoryDateFrom').val());
                d.date_to = toIsoDateReports($('#cycleReportHistoryDateTo').val());
            },
        },
        columns: [
            { data: 'generated_at', render: { display: (v) => formatDisplayDateTime(v), sort: (v) => v, filter: (v) => v } },
            { data: null, render: (l) => escapeHtmlReports(rdReportByLabelReports(l)) },
            { data: null, render: (l) => escapeHtmlReports(rdReportLanguageLabelReports(l)) },
            { data: null, render: (l) => escapeHtmlReports(rdReportDeviceLabelReports(l)) },
            { data: null, render: (l) => escapeHtmlReports(rdReportBrowserLabelReports(l)) },
            { data: 'ip_address', render: (v) => escapeHtmlReports(v || '-') },
            { data: 'source', render: (v) => escapeHtmlReports(v || '-') },
        ],
        initComplete: function () {
            initExcelColumnFilters(this.api(), {
                mode: 'client',
                columns: [
                    { index: 1, key: 'cycle_history_by' },
                    { index: 2, key: 'cycle_history_language' },
                    { index: 3, key: 'cycle_history_device' },
                    { index: 4, key: 'cycle_history_browser' },
                    { index: 5, key: 'cycle_history_ip' },
                    { index: 6, key: 'cycle_history_source' },
                ]
            });
        },
    });
}
$(document).on('click', '.btn-cycle-report-history', function () {
    const row = cycleReportRows.find(r => r.code === $(this).data('code'));
    if (!row || !selectedCycleRunId) return;
    openReportHistoryModal(row.code, reportLabel(row), { payroll_run_id: selectedCycleRunId });
});
$(document).on('click', '.btn-annual-report-history', function () {
    const report = annualReportRows().find(r => r.code === $(this).data('code'));
    const year = $('#reportsPeriodYear').val();
    if (!report || !year) return;
    openReportHistoryModal(report.code, reportLabel(report), { period_year: year });
});
$(document).on('click', '#cycleReportHistoryStationFilterToggle', function () {
    const $filter = $('#cycleReportHistoryStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('change', '#cycleReportHistoryDateFrom, #cycleReportHistoryDateTo', reloadCycleReportHistoryTable);
$(document).on('click', '#btnCycleReportHistoryClearFilter', function () {
    $('#cycleReportHistoryDateFrom, #cycleReportHistoryDateTo').val('');
    reloadCycleReportHistoryTable();
});

/* ---------- Export History tab ---------- */
let tb_export_history;
function reportNameByCode(code) {
    const report = reportList.find(r => r.code === code);
    return report ? reportLabel(report) : code;
}
function initExportHistoryTable() {
    if ($.fn.DataTable.isDataTable('#tb_export_history')) {
        $('#tb_export_history').DataTable().ajax.reload(null, false);
        return;
    }
    tb_export_history = $('#tb_export_history').DataTable({
        responsive: true,
        order: [[0, 'desc']],
        ajax: {
            url: `${BASE_URL}/api/report.export-logs`,
            dataSrc: 'data',
            data: function (d) {
                d.report_type = $('#filter_export_report_type').val() || '';
                d.date_from = toIsoDateReports($('#exportHistoryDateFrom').val());
                d.date_to = toIsoDateReports($('#exportHistoryDateTo').val());
            }
        },
        columns: [
            // object-form render: only 'display' gets the dd/mm/yyyy formatting -- 'sort'/'filter'
            // stay on the raw ISO string, since this is a CLIENT-side table (no serverSide) and
            // sorting/filtering on the dd/mm/yyyy display string would sort lexicographically
            // ("05/09" before "26/08") instead of chronologically.
            { data: 'generated_at', render: { display: d => formatDisplayDateTime(d), sort: d => d, filter: d => d } },
            { data: 'report_code', render: d => reportNameByCode(d) },
            { data: 'format', render: d => formatLabel(d) },
            { data: 'file_name' },
            { data: null, render: (d, t, row) => (currentLang === 'th' ? row.generated_by_name_th : row.generated_by_name_en) || row.generated_by_name_th || row.generated_by_name_en || '-' },
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        drawCallback: function () { getTableLang(); },
        // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
        // rollout, client mode. No actions column on this table -- every column is filterable.
        initComplete: function () {
            initExcelColumnFilters(this.api(), {
                mode: 'client',
                columns: [
                    { index: 0, key: 'generated_at' },
                    { index: 1, key: 'report_code' },
                    { index: 2, key: 'format' },
                    { index: 3, key: 'file_name' },
                    { index: 4, key: 'generated_by' },
                ]
            });
        }
    });
}

$(document).on('change', '#filter_export_report_type', function () {
    if (tb_export_history) tb_export_history.ajax.reload(null, true);
});
$(document).on('click', '#exportHistoryStationFilterToggle', function () {
    const $filter = $('#exportHistoryStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
function updateExportHistoryClearFilterVisibility() {
    const active = !!($('#filter_export_report_type').val() || $('#exportHistoryDateFrom').val() || $('#exportHistoryDateTo').val());
    $('#btnExportHistoryClearFilter').toggleClass('d-none', !active);
}
$(document).on('change', '#exportHistoryDateFrom, #exportHistoryDateTo', function () {
    updateExportHistoryClearFilterVisibility();
    if (tb_export_history) tb_export_history.ajax.reload(null, true);
});
$(document).on('change', '#filter_export_report_type', updateExportHistoryClearFilterVisibility);
$(document).on('click', '#btnExportHistoryClearFilter', function () {
    $('#filter_export_report_type').val(null).trigger('change');
    $('#exportHistoryDateFrom, #exportHistoryDateTo').val('');
    updateExportHistoryClearFilterVisibility();
    if (tb_export_history) tb_export_history.ajax.reload(null, true);
});

$(document).ready(function () {
    loadReportList();
    loadCycleRuns();
    loadAvailableYears();
    if (typeof initSelect2 === 'function') {
        initSelect2('#filter_export_report_type', { mode: 'static', allowClear: true });
    }
    if (typeof initDatepicker === 'function') {
        initDatepicker('#exportHistoryDateFrom');
        initDatepicker('#exportHistoryDateTo');
    }
    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
        const tabId = $(e.target).attr('id');
        if (tabId === 'history-tab') {
            initExportHistoryTable();
        }
        if (tabId === 'cycle-tab') {
            const activeType = $('#cycleReportTypeTabs button.active').data('report-type') || 'statutory';
            ensureCycleTypeInited(activeType);
        }
        $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
    });
});
