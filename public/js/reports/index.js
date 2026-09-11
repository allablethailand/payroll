let reportList = [];

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
 *
 * 2026-09-04, Backlog Phase 10, T060 Step D: TH_PND1/TH_SSO110 are the ONE deliberate exception to
 * "cycle entries mirror CYCLE_REPORT_CODES exactly" above -- they're `frequency:'annual'` HERE
 * (so they also gain the Annual tab's year+month picker, for a non-monthly-frequency company's
 * real monthly filing) while STILL remaining in `CYCLE_REPORT_CODES` server-side (so the existing
 * per-run entry point on the Per-Cycle tab, and the Process Detail page's own RUN_REPORT_SHORTCUTS,
 * both keep working exactly as before -- confirmed neither of those reads this map's `frequency`
 * field at all). Both generation modes are real and intentionally coexist for these 2 codes only.
 */
const REPORT_META = {
    // 2026-09-04, Backlog Phase 10, T060 Step D: TH_PND1/TH_SSO110 are real monthly government
    // filings -- for a company on a non-monthly payroll_frequency (weekly/bi_weekly/semi_monthly/
    // daily), the correct submission must aggregate EVERY settled run in that calendar month, not
    // just one. `frequency:'cycle'` here is ALREADY dead for driving Per-Cycle tab membership (see
    // this const's own top-of-file comment -- that tab reads ReportsController::CYCLE_REPORT_CODES
    // instead, a separate PHP list, UNCHANGED by this edit, so the existing per-run generation
    // entry point on that tab keeps working exactly as before for every monthly-frequency company).
    // Adding `frequency:'annual', extra:['month']` here is therefore purely ADDITIVE -- it makes
    // annualReportRows() ALSO include these two, giving them the SAME year+month picker TH_SSO609
    // already uses, without removing anything. PndOneReport::generate()/Sso110Report::generate()
    // both now accept EITHER run_id (existing path, byte-identical) or year+month (this new path).
    TH_PND1: { frequency: 'annual', extra: ['month'] },
    TH_SSO110: { frequency: 'annual', extra: ['month'] },
    TH_SLF: { frequency: 'cycle', extra: [] },
    PAY_SLIP: { frequency: 'cycle', extra: ['employee'] },
    BANK_TRANSFER_FILE: { frequency: 'cycle', extra: [] },
    PAYROLL_REGISTER: { frequency: 'cycle', extra: [] },
    // 2026-08-31, same-day follow-up, explicit request: CashPaymentSummaryReport existed already
    // (built alongside the Cash Payments tab) but was never surfaced on this page -- same
    // "per-run, cycle-frequency, no extra picker" shape as PAYROLL_REGISTER/BANK_TRANSFER_FILE.
    CASH_PAYMENT_SUMMARY: { frequency: 'cycle', extra: [] },
    // 2026-09-07: DeductionBreakdownReport -- 'cycle' for the same documentation-only reason as
    // every other cycle entry above (see this const's own top-of-file comment); its real config
    // step is server-driven via ReportsController::CYCLE_REPORT_NEEDS_CONFIG, not this map's `extra`.
    DEDUCTION_BREAKDOWN: { frequency: 'cycle', extra: [] },
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
// 2026-09-02, explicit request: circular row-action buttons (see style.css's own
// ".btn-circle-action" section) replace the old adjacent .btn-group.
function annualReportActionsHtml(report) {
    return `<div class="d-flex gap-1 justify-content-center">
        <button type="button" class="btn btn-link btn-circle-action text-primary btn-annual-report-download" data-code="${report.code}" title="${langData['report_preview_and_download'] || 'Preview & Download'}"><i class="fa-solid fa-download"></i></button>
        <button type="button" class="btn btn-link btn-circle-action text-secondary btn-annual-report-history" data-code="${report.code}" title="${langData['report_view_history'] || 'View Download History'}"><i class="fa-solid fa-clock-rotate-left"></i></button>
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
            { data: null, render: (d, t, report) => `<span class="reports-row-report-type-icon rt-${report.report_type}"><i class="fa-solid ${REPORT_TYPE_ICONS[report.report_type] || 'fa-file-lines'}"></i></span><span class="reports-row-report-name">${escapeHtml(reportLabel(report))}</span>` },
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
    $('#reportsPreviewDeductionCodesWrap').toggleClass('d-none', !extra.deductionCodes);
    if (extra.deductionCodes) {
        loadDeductionCodesForRun(params.get('run_id'));
    } else {
        renderReportsPreviewFrame();
    }
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
    if (options.extra && options.extra.deductionCodes) {
        const codes = $('.reports-preview-deduction-code-checkbox:checked').map(function () { return $(this).val(); }).get();
        params.set('deduction_codes', codes.join(','));
    }
    return params;
}
// 2026-09-07: DeductionBreakdownReport's own checkbox picker -- fetches the codes that actually
// occurred in this run (default all-checked, per the explicit "Default คือเลือกทั้งหมด" request) and
// renders them into the dropdown-menu, then refreshes the preview once populated.
function loadDeductionCodesForRun(runId) {
    $.getJSON(`${BASE_URL}/api/report.deduction-types-for-run`, { run_id: runId }, function (res) {
        if (!res.status) return;
        const codes = res.data || [];
        const $menu = $('#reportsPreviewDeductionCodesMenu').empty();
        if (!codes.length) {
            $menu.append(`<div class="text-muted small px-1" data-i18n="deduction_report_no_types">No deductions found in this run.</div>`);
            if (typeof applyLanguage === 'function') applyLanguage();
            updateDeductionCodesCount();
            renderReportsPreviewFrame();
            return;
        }
        $menu.append(
            '<div class="d-flex justify-content-between mb-2 px-1">'
            + '<a href="#" class="small" id="rpdcSelectAll" data-i18n="select_all">Select All</a>'
            + '<a href="#" class="small" id="rpdcSelectNone" data-i18n="select_none">Select None</a>'
            + '</div>'
        );
        codes.forEach(function (c) {
            const label = currentLang === 'th' ? c.name_th : (c.name_en || c.name_th);
            const id = 'rpdc_' + c.code.replace(/[^a-zA-Z0-9_-]/g, '_');
            $menu.append(
                '<div class="form-check">'
                + `<input class="form-check-input reports-preview-deduction-code-checkbox" type="checkbox" value="${escapeAttr(c.code)}" id="${id}" checked>`
                + `<label class="form-check-label small" for="${id}">${escapeHtml(label)}</label>`
                + '</div>'
            );
        });
        if (typeof applyLanguage === 'function') applyLanguage();
        updateDeductionCodesCount();
        renderReportsPreviewFrame();
    });
}
function updateDeductionCodesCount() {
    $('#reportsPreviewDeductionCodesCount').text($('.reports-preview-deduction-code-checkbox:checked').length);
}
$(document).on('change', '.reports-preview-deduction-code-checkbox', function () {
    updateDeductionCodesCount();
    renderReportsPreviewFrame();
});
$(document).on('click', '#rpdcSelectAll', function (e) {
    e.preventDefault();
    $('.reports-preview-deduction-code-checkbox').prop('checked', true);
    updateDeductionCodesCount();
    renderReportsPreviewFrame();
});
$(document).on('click', '#rpdcSelectNone', function (e) {
    e.preventDefault();
    $('.reports-preview-deduction-code-checkbox').prop('checked', false);
    updateDeductionCodesCount();
    renderReportsPreviewFrame();
});
function renderReportsPreviewFrame() {
    const params = currentReportsPreviewParams();
    const $frame = $('#reportsPreviewFrame').off('load').addClass('d-none').attr('src', '');
    const $loading = $('#reportsPreviewLoading').addClass('d-none');
    const $unavailable = $('#reportsPreviewUnavailable').addClass('d-none');
    const $selectEmployee = $('#reportsPreviewSelectEmployee').addClass('d-none');
    const $selectDeduction = $('#reportsPreviewSelectDeduction').addClass('d-none');

    // Payment Voucher (the only report with extra.employee) needs an employee picked before there's
    // anything to preview at all -- show a plain hint instead of firing a request that would just
    // 400 server-side for a missing employee_id.
    if (reportsPreviewOptions.extra && reportsPreviewOptions.extra.employee && !params.get('employee_id')) {
        $('#reportsPreviewDialog').removeClass('modal-xl');
        $selectEmployee.removeClass('d-none');
        return;
    }
    // Same "nothing to preview yet" idea as the employee guard above, own copy/icon for "every
    // deduction checkbox unchecked" -- avoids firing a request that would just 400 server-side.
    if (reportsPreviewOptions.extra && reportsPreviewOptions.extra.deductionCodes
        && $('.reports-preview-deduction-code-checkbox').length && !$('.reports-preview-deduction-code-checkbox:checked').length) {
        $('#reportsPreviewDialog').removeClass('modal-xl');
        $selectDeduction.removeClass('d-none');
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
    if (reportsPreviewOptions.extra && reportsPreviewOptions.extra.deductionCodes
        && $('.reports-preview-deduction-code-checkbox').length && !$('.reports-preview-deduction-code-checkbox:checked').length) {
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

/* ---------- Per-Cycle Reports tab (2026-09-07 rebuild): a run x report-type MATRIX -- one ROW per
   completed run, one COLUMN per applicable report, a single print button per cell (explicit
   request: "เปลี่ยนเป็น ตารางแสดงรอบที่สามารถพิมพ์ได้ แล้วให้มี column พิมพ์ตามแบบที่พิมพ์ได้"). Reuses
   openReportsPreview()/openPayslipRoster() above/below for the actual Download action -- only HOW
   a run+report pair is picked changed, not what happens once it is. See
   app/controllers/ReportsController.php's own cycleRunsMatrix() docblock for the backend shape and
   PayrollReportDataModel::getCompletedRuns()'s own docblock for this tab's full back-and-forth
   design history (matrix -> single-run picker -> matrix again). */

const REPORT_TYPE_ICONS = { statutory: 'fa-landmark', payment: 'fa-money-check-dollar', internal: 'fa-building' };
// 2026-09-08, explicit request: "th มี icon แล้วดูรกตัดออกไปเลยครับ และจัดกลุ่มรายงานได้ไหมครับ กลุ่มไหนเป็น
// กลุ่มเดียวกันให้เป็นปุ่มที่มี dropdown ให้เลือก" -- was one COLUMN per individual report code (up to 8,
// per ReportsController::CYCLE_REPORT_CODES), each header carrying its own report-type icon +
// label, which got cluttered fast. Now one column PER report_type GROUP (statutory/payment/
// internal, same 3-way split Export History's own filter already uses -- reusing its i18n keys) --
// the header is now a plain text label (no icon at all, per the explicit "ตัดออกไปเลยครับ"), and each
// cell is a single dropdown-toggle button listing only that group's codes actually applicable to
// that run (data-run-id/data-code preserved on each item, so the existing .btn-cycle-matrix-print
// delegated click handler below needs no changes at all).
const REPORT_GROUP_ORDER = ['statutory', 'payment', 'internal'];
const REPORT_GROUP_LABEL_KEYS = { statutory: 'report_type_statutory', payment: 'report_type_payment', internal: 'report_type_internal' };
// 2026-09-08, same-day follow-up: "ใน dropdown ใส่ icon เข้าไปได้ครับของแต่ละรายงาน" -- a distinct icon
// PER REPORT CODE (not just one shared per group) so the dropdown items are visually scannable, not
// just a plain text list. Falls back to the group's own icon for any code not listed here (future-
// proofing a new code added to ReportsController::CYCLE_REPORT_CODES later without this map being
// updated in lockstep).
const REPORT_CODE_ICONS = {
    TH_PND1: 'fa-file-invoice-dollar',
    TH_SSO110: 'fa-hand-holding-medical',
    TH_SLF: 'fa-graduation-cap',
    PAY_SLIP: 'fa-file-invoice',
    BANK_TRANSFER_FILE: 'fa-building-columns',
    PAYROLL_REGISTER: 'fa-table-list',
    CASH_PAYMENT_SUMMARY: 'fa-money-bill-wave',
    DEDUCTION_BREAKDOWN: 'fa-chart-pie',
};

let cycleMatrixRuns = [];    // [{id, run_name, cycle_name, period_start_date, period_end_date, payment_date, state, applicable_codes:[code,...]}]
let cycleMatrixColumns = []; // [{code, report_type, format, per_employee, label}] -- union of every code applicable to at least one returned run
let tbCycleMatrix = null;

// Groups derived fresh from cycleMatrixColumns every render (not hardcoded) -- the column SET
// itself can differ between filter results (a narrower date range might exclude the one run that
// had SSO-active employees, say), and a group with zero columns present this time is simply omitted
// rather than showing an always-empty "-" column.
function cycleMatrixGroups() {
    const byType = {};
    cycleMatrixColumns.forEach(function (col) {
        (byType[col.report_type] = byType[col.report_type] || []).push(col);
    });
    return REPORT_GROUP_ORDER.filter(t => byType[t] && byType[t].length).map(t => ({ type: t, columns: byType[t] }));
}
function cycleMatrixGroupLabel(group) {
    return langData[REPORT_GROUP_LABEL_KEYS[group.type]] || group.type;
}
function cycleMatrixGroupCellHtml(run, group) {
    const applicable = group.columns.filter(col => run.applicable_codes.includes(col.code));
    if (!applicable.length) {
        return '<span class="text-muted">&ndash;</span>';
    }
    const groupIcon = REPORT_TYPE_ICONS[group.type] || 'fa-file-lines';
    const items = applicable.map(function (col) {
        const itemIcon = REPORT_CODE_ICONS[col.code] || groupIcon;
        return `<li><button type="button" class="dropdown-item btn-cycle-matrix-print" data-run-id="${run.id}" data-code="${col.code}"><i class="fa-solid ${itemIcon} me-2 text-muted"></i>${escapeHtml(reportLabel(col))}</button></li>`;
    }).join('');
    return `<div class="dropdown">
        <button type="button" class="btn btn-link btn-circle-action text-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="${escapeAttr(langData['report_preview_and_download'] || 'Preview & Download')}"><i class="fa-solid ${groupIcon}"></i></button>
        <ul class="dropdown-menu dropdown-menu-end">${items}</ul>
    </div>`;
}
function loadCycleRunsMatrix() {
    $.ajax({
        url: `${BASE_URL}/api/report.cycle-runs-matrix`,
        method: 'GET',
        dataType: 'json',
        data: {
            date_from: toIsoDateReports($('#cycleReportDateFrom').val()),
            date_to: toIsoDateReports($('#cycleReportDateTo').val()),
        },
        success: function (res) {
            if (!res.status) return;
            cycleMatrixRuns = (res.data && res.data.runs) || [];
            cycleMatrixColumns = (res.data && res.data.report_columns) || [];
            renderCycleMatrixTable();
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while loading the data.');
        }
    });
}
function renderCycleMatrixTable() {
    const hasRows = cycleMatrixRuns.length > 0;
    $('#cycleReportsNoRunBanner').toggleClass('d-none', hasRows);
    $('#cycleMatrixTableWrap').toggleClass('d-none', !hasRows);
    if ($.fn.DataTable.isDataTable('#tb_cycle_matrix')) {
        tbCycleMatrix.destroy();
    }
    $('#tb_cycle_matrix').empty();
    if (!hasRows) return;

    const groups = cycleMatrixGroups();

    // Column SET (which report-type groups appear as columns at all) can differ between filter
    // results -- the <thead> is rebuilt from scratch every time alongside the `columns` config
    // below, rather than written once as static markup, so the two can never drift out of sync.
    // 2026-09-08, same-day follow-up: "column export อยากให้กองอยู่ด้านขวา เพิ่มชื่อรอบกับจำนวนพนักงานเข้าไป
    // ด้วยครับ" -- Cycle (payroll_cycle) + Employees (table_employee_count, already-cached
    // payroll_runs.employee_count, see PayrollReportDataModel::getCompletedRuns()'s own comment) join
    // the info columns BEFORE the export-group dropdowns, which stay exactly where they already
    // were -- the LAST columns -- so this addition is what actually piles every export action
    // together on the right, rather than moving the groups themselves.
    let headHtml = '<thead class="table-light text-secondary"><tr>'
        + `<th data-i18n="table_payroll_run">${escapeHtml(langData['table_payroll_run'] || 'Payroll Run')}</th>`
        + `<th data-i18n="payroll_cycle">${escapeHtml(langData['payroll_cycle'] || 'Payroll Schedule')}</th>`
        + `<th data-i18n="pay_period">${escapeHtml(langData['pay_period'] || 'Pay Period')}</th>`
        + `<th class="text-center" data-i18n="table_employee_count">${escapeHtml(langData['table_employee_count'] || 'Employees')}</th>`;
    // 2026-09-08, same-day follow-up: "Column ของทั้ง 3 ปุ่มอยากให้ลดความกว้างลงให้เท่ากัน และไปรวมอยู่ฝั่งขวา
    // ของตารางจะดูเป็นระเบียบกว่าครับ" -- these 3 columns were already the LAST (rightmost) ones, but
    // with no explicit width DataTables/the browser's own auto table layout let them absorb leftover
    // space (since the info columns beside them don't fill the container on their own), so they ended
    // up wide and spread apart instead of reading as one tight cluster. `.reports-matrix-group-col`
    // (this page's own <style> block) pins them to a small, EQUAL, fixed width -- the info columns
    // (which have no explicit width) absorb whatever space is left instead, which is what actually
    // pulls the 3 buttons together into a compact group at the right edge.
    groups.forEach(function (group) {
        headHtml += `<th class="text-center reports-matrix-group-col">${escapeHtml(cycleMatrixGroupLabel(group))}</th>`;
    });
    headHtml += '</tr></thead><tbody></tbody>';
    $('#tb_cycle_matrix').html(headHtml);

    const columns = [
        { data: null, render: { display: (d, t, run) => `<span class="reports-row-report-name">${escapeHtml(run.run_name || run.cycle_name || '-')}</span>`, sort: (d, t, run) => run.run_name || run.cycle_name || '', filter: (d, t, run) => run.run_name || run.cycle_name || '' } },
        { data: null, render: (d, t, run) => escapeHtml(run.cycle_name || '-') },
        { data: null, render: { display: (d, t, run) => `${formatDisplayDate(run.period_start_date)} - ${formatDisplayDate(run.period_end_date)}`, sort: (d, t, run) => run.period_start_date, filter: (d, t, run) => run.period_start_date } },
        { data: null, className: 'text-center', render: (d, t, run) => (run.employee_count || 0) },
    ];
    groups.forEach(function (group) {
        columns.push({ data: null, className: 'text-center reports-matrix-group-col', orderable: false, render: (d, t, run) => cycleMatrixGroupCellHtml(run, group) });
    });
    tbCycleMatrix = $('#tb_cycle_matrix').DataTable({
        data: cycleMatrixRuns,
        responsive: true,
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        columns: columns,
        // 2026-09-10, Batch 3A item 1 -- this table's own dropdown-clipping fix (a `.table-responsive`
        // wrapper forcing overflow-y:auto, catching the dropdown-menu when there are few rows) is now
        // handled globally by app.js's own applyFixedStrategyToTableDropdowns() on every `draw.dt`,
        // superseding the per-table drawCallback that used to live here.
        drawCallback: function () { getTableLang(); },
    });
}
$(document).on('click', '.btn-cycle-matrix-print', function () {
    const runId = $(this).data('run-id');
    const code = $(this).data('code');
    const col = cycleMatrixColumns.find(c => c.code === code);
    if (!col) return;
    if (col.per_employee) {
        openPayslipRoster(col, runId);
        return;
    }
    const params = new URLSearchParams();
    params.set('report_code', col.code);
    params.set('format', col.format);
    params.set('run_id', runId);
    if (col.needs_config === 'deduction_codes') {
        // DeductionBreakdownReport: excel/pdf choice AND the deduction-type checkbox picker both
        // live in #reportsPreviewModal's own footer (see openReportsPreview()'s `extra` handling).
        openReportsPreview(params, reportLabel(col), { formatOptions: ['pdf', 'excel'], extra: { deductionCodes: true } });
        return;
    }
    openReportsPreview(params, reportLabel(col));
});
function updateCycleReportFilterVisibility() {
    const active = !!($('#cycleReportDateFrom').val() || $('#cycleReportDateTo').val());
    $('#cycleReportFilterClearRow').toggleClass('d-none', !active);
}
$(document).on('changeDate', '#cycleReportDateFrom, #cycleReportDateTo', function () {
    updateCycleReportFilterVisibility();
    loadCycleRunsMatrix();
});
$(document).on('click', '#btnCycleReportClearFilter', function () {
    $('#cycleReportDateFrom').val('').datepicker('update');
    $('#cycleReportDateTo').val('').datepicker('update');
    updateCycleReportFilterVisibility();
    loadCycleRunsMatrix();
});

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
            $select.trigger('change'); // #reportsPeriodYear (.select2-native) is already select2-initialized by app.js's own global page-load pass -- appending real <option>s then triggering 'change' is Select2's standard way to refresh an already-initialized widget's option list
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

/* ---------- Pay Slip roster picker (2026-08-29, explicit request: "ปรับให้ขึ้นเป็นรายชื่อพนักงานมาเลย และ
   emp code ด้วย แผนกตำแหน่งทีม และมีปุ่มให้กด Download และแสดงด้วยว่า Download ไปแล้วกี่ครั้ง") ---------- */
let tb_payslip_roster;
// 2026-09-07, real bug found and fixed -- this used to read the Per-Cycle tab's own
// `selectedCycleRunId` module variable (set by the old single-run-picker's change handler), which
// no longer exists now that tab is a matrix with no single "currently selected" run at all. The
// roster modal itself is still scoped to exactly one run per opening though, so it needs its own
// small piece of state -- set here, read by the roster's own Download button handler below.
let payslipRosterRunId = null;
function employeeNameReports(row) {
    if (currentLang === 'th') return `${row.name_th || ''} ${row.surname_th || ''}`.trim() || row.name_en || '-';
    return `${row.name_en || ''} ${row.surname_en || ''}`.trim() || row.name_th || '-';
}
function openPayslipRoster(row, runId) {
    payslipRosterRunId = runId;
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
            { data: null, render: (d, t, r) => escapeHtml(employeeNameReports(r)) },
            { data: null, render: (d, t, r) => escapeHtml((currentLang === 'th' ? r.department_name_th : r.department_name_en) || r.department_name_th || '-') },
            { data: null, render: (d, t, r) => escapeHtml((currentLang === 'th' ? r.position_name_th : r.position_name_en) || r.position_name_th || '-') },
            { data: null, render: (d, t, r) => escapeHtml((currentLang === 'th' ? r.team_name_th : r.team_name_en) || r.team_name_th || '-') },
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
    params.set('run_id', payslipRosterRunId);
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
            { data: null, render: (l) => escapeHtml(rdReportByLabelReports(l)) },
            { data: null, render: (l) => escapeHtml(rdReportLanguageLabelReports(l)) },
            { data: null, render: (l) => escapeHtml(rdReportDeviceLabelReports(l)) },
            { data: null, render: (l) => escapeHtml(rdReportBrowserLabelReports(l)) },
            { data: 'ip_address', render: (v) => escapeHtml(v || '-') },
            { data: 'source', render: (v) => escapeHtml(v || '-') },
        ],
        // 2026-08-30, real gap found and fixed (full-codebase pageLength audit) -- was missing
        // entirely, silently falling back to DataTables' own built-in default of 10. Same shape as
        // payroll/detail.js's own dtReportHistory (the per-run equivalent of this cycle-wide one).
        pageLength: pageLength,
        lengthMenu: lengthMenu,
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
    $('#exportHistoryFilterClearRow').toggleClass('d-none', !active);
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
    (window.langReady || Promise.resolve()).then(function () {
    loadReportList();
    loadCycleRunsMatrix();
    loadAvailableYears();
    if (typeof initSelect2 === 'function') {
        initSelect2('#filter_export_report_type', { mode: 'static', allowClear: true });
    }
    if (typeof initDatepicker === 'function') {
        initDatepicker('#cycleReportDateFrom');
        initDatepicker('#cycleReportDateTo');
        initDatepicker('#exportHistoryDateFrom');
        initDatepicker('#exportHistoryDateTo');
    }
    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
        const tabId = $(e.target).attr('id');
        if (tabId === 'history-tab') {
            initExportHistoryTable();
        }
        $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
    });
    });
});
