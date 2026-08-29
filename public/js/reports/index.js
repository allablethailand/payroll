let reportList = [];
let cycleRuns = [];
const cycleDataReady = { reports: false, runs: false };

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
 */
// 2026-08-29: `extra` no longer needs a 'language' value -- cycleExportCellHtml() now offers a
// Thai/English choice unconditionally for every cycle report (see that function's own docblock).
// `extra` here is only for report-specific ADDITIONAL fields beyond format+language: 'employee'
// (Pay Slip has no run-level export, opens an employee picker instead).
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

function reportLabel(report) {
    return (currentLang === 'th' ? report.label.th : report.label.en) || report.label.th || report.label.en || report.code;
}
function formatLabel(fmt) {
    const key = 'format_' + fmt;
    return langData[key] || fmt.toUpperCase();
}

/** Annual Reports tab only (Per-Cycle Reports moved to a matrix table, see below). */
function buildReportCard(report) {
    const meta = REPORT_META[report.code] || { frequency: 'annual', extra: [] };
    const tplEl = document.getElementById('reportCardTemplate');
    const clone = tplEl.content.cloneNode(true);
    const wrapper = document.createElement('div');
    wrapper.appendChild(clone);
    const $card = $(wrapper.children);
    $card.find('.report-card-label').text(reportLabel(report));
    const $form = $card.find('.report-generate-form');
    $form.attr('data-report-code', report.code);

    if (meta.extra.includes('month')) $form.find('.field-month').removeClass('d-none');
    if (meta.extra.includes('employee')) $form.find('.field-employee').removeClass('d-none');

    const $formatSelect = $form.find('.field-format-input');
    const formatKeys = report.supported_formats.map(f => 'format_' + f);
    $formatSelect.attr('data-report-formats', report.supported_formats.join(','));
    $formatSelect.attr('data-format-keys', formatKeys.join(','));

    return $card;
}

/** Hides a `.reports-type-section` (the Statutory/Payment/Internal subsection heading + its own
 *  card grid) when nothing landed in it -- e.g. Annual has no Internal reports at all today, so
 *  that subsection would otherwise render as a bare heading with an empty grid underneath. */
function toggleEmptyTypeSections(frequency) {
    ['statutory', 'payment', 'internal'].forEach(function (type) {
        const $section = $(`#reportCards_${frequency}_${type}`).closest('.reports-type-section');
        $section.toggleClass('d-none', $(`#reportCards_${frequency}_${type}`).children().length === 0);
    });
}

/** Annual Reports tab only. */
function renderReportCards(reports) {
    const containers = { statutory: [], payment: [], internal: [] };
    reports.forEach(function (r) {
        const meta = REPORT_META[r.code] || { frequency: 'annual' };
        if (meta.frequency !== 'annual') return;
        if (containers[r.report_type]) containers[r.report_type].push(r);
    });

    let totalInFrequency = 0;
    ['statutory', 'payment', 'internal'].forEach(function (type) {
        const $container = $(`#reportCards_annual_${type}`).empty();
        const list = containers[type];
        totalInFrequency += list.length;
        list.forEach(function (report) {
            $container.append(buildReportCard(report));
        });
    });
    $('#noReports_annual').toggleClass('d-none', totalInFrequency > 0);
    toggleEmptyTypeSections('annual');

    // Init Select2 for every card now that they're in the DOM.
    if (typeof initSelect2 === 'function') {
        $('.field-employee-input').each(function () { initSelect2(this, { mode: 'ajax' }); });
        $('.field-month-input').each(function () { initSelect2(this, { mode: 'static' }); });
        $('.field-format-input').each(function () {
            const keys = ($(this).attr('data-format-keys') || '').split(',').filter(Boolean);
            const values = ($(this).attr('data-report-formats') || '').split(',').filter(Boolean);
            initSelect2(this, { mode: 'static', keys: keys, values: values });
        });
        $('.field-language-input').each(function () {
            initSelect2(this, { mode: 'static' });
            $(this).val('th').trigger('change');
        });
    }
}

function loadReportList() {
    $.ajax({
        url: `${BASE_URL}/api/report.list`,
        method: 'GET',
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                reportList = res.data;
                renderReportCards(reportList);
                cycleDataReady.reports = true;
                if (cycleDataReady.runs) renderCycleReportTables();
            }
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while loading the data.');
        }
    });
}

// generateReport(url) moved to public/js/app.js (2026-08-29) -- loaded on every page now so the
// Payroll Process List/Detail pages' own report shortcut buttons can reuse it too, not just this
// page. See app.js for the implementation (unchanged).

// Annual Reports tab only (Per-Cycle Reports moved to a matrix table, own handlers further below).
$(document).on('submit', '.report-generate-form', function (e) {
    e.preventDefault();
    const $form = $(this);
    const reportCode = $form.attr('data-report-code');

    const year = $('#reportsPeriodYear').val();
    if (!year) {
        showWarning(langData['select_year_first'] || 'Please select a year first.');
        return;
    }

    let firstInvalid = null;
    $form.find('.required').each(function () {
        const $el = $(this);
        if ($el.closest('.d-none').length > 0) return;
        const value = ($el.val() || '').toString().trim();
        if (!value) {
            $el.addClass('is-invalid');
            if (!firstInvalid) firstInvalid = $el;
        } else {
            $el.removeClass('is-invalid');
        }
    });
    if (firstInvalid) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }

    const params = new URLSearchParams();
    params.set('report_code', reportCode);
    params.set('format', $form.find('.field-format-input').val());
    params.set('year', year);
    if (!$form.find('.field-month').hasClass('d-none')) {
        params.set('month', $form.find('.field-month-input').val());
    }
    if (!$form.find('.field-employee').hasClass('d-none')) {
        params.set('employee_id', $form.find('.field-employee-input').val());
    }
    params.set('language', $form.find('.field-language-input').val() || 'th');

    generateReport(`${BASE_URL}/api/report.generate?${params.toString()}`);
});

/* ---------- Per-Cycle Reports tab: run x report-type matrix (2026-08-27) ---------- */

function escapeHtmlReports(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}

function cycleStateBadge(state) {
    const map = {
        draft: 'bg-secondary-subtle text-secondary',
        pending_approval: 'bg-warning-subtle text-warning',
        approved: 'bg-info-subtle text-info',
        paid: 'bg-success-subtle text-success',
        locked: 'bg-dark-subtle text-dark',
        rejected: 'bg-danger-subtle text-danger',
        cancelled: 'bg-dark-subtle text-muted',
        need_info: 'bg-primary-subtle text-primary',
    };
    const cls = map[state] || 'bg-light text-dark';
    const text = langData['state_' + state] || state;
    return `<span class="badge ${cls}">${text}</span>`;
}

function runPeriodLabel(run) {
    const start = (typeof formatDisplayDate === 'function') ? formatDisplayDate(run.period_start_date) : run.period_start_date;
    const end = (typeof formatDisplayDate === 'function') ? formatDisplayDate(run.period_end_date) : run.period_end_date;
    const name = run.run_name || run.cycle_name || '-';
    return `<div class="fw-semibold">${escapeHtmlReports(name)}</div><div class="text-secondary small">${start} - ${end}</div>`;
}

function cycleReportsByType(type) {
    return reportList.filter(function (r) {
        const meta = REPORT_META[r.code] || { frequency: 'cycle' };
        return meta.frequency === 'cycle' && r.report_type === type;
    });
}

const REPORT_LANGUAGES = [
    { code: 'th', flag: 'th.png', key: 'language_th', fallback: 'Thai' },
    { code: 'en', flag: 'gb.png', key: 'language_en', fallback: 'English' },
];

/** One Export cell per report column -- a Thai/English x format dropdown for every report (a
 *  single format just skips showing the format name in the item label), or (a report scoped to
 *  one employee, e.g. Pay Slip -- there's no run-level version of it to export) a button that
 *  opens the employee-picker modal instead (that modal's own confirm button carries a language
 *  choice too, see its own markup/handler).
 *  2026-08-29, explicit follow-up request: "ตัวออกรายงาน ที่เลือกได้ว่า en หรือ th ต้องออกได้จากทุกหน้าที่มี
 *  ปุ่ม Export ครับ ตอนนี้เหมือนยังเลือกไม่ได้ครับ" -- was opt-in per report via REPORT_META's own
 *  `extra: ['language']` (only BANK_TRANSFER_FILE/TH_SSO110 had it); now unconditional for every
 *  cycle report. A report whose own generate() never reads context.language (most of them, still)
 *  just silently ignores the extra query param -- same "unused key" tolerance
 *  ReportsController::generate()'s own context-building already relies on for year/month/
 *  employee_id today, so this is safe to turn on everywhere without auditing each report first. */
function cycleExportCellHtml(report, run) {
    const meta = REPORT_META[report.code] || { frequency: 'cycle', extra: [] };
    const formats = report.supported_formats || [];
    const label = reportLabel(report);
    if (meta.extra.includes('employee')) {
        return `<button type="button" class="btn btn-sm btn-outline-secondary cycle-export-employee-btn"
            data-report-code="${report.code}" data-run-id="${run.id}" title="${escapeHtmlReports(label)}">
            <i class="fa-solid fa-file-export"></i></button>`;
    }
    const items = [];
    formats.forEach(function (fmt) {
        REPORT_LANGUAGES.forEach(function (lng) {
            const fmtLabel = formats.length > 1 ? `${formatLabel(fmt)} - ` : '';
            items.push(`<li><a class="dropdown-item cycle-export-btn" href="#" data-report-code="${report.code}" data-run-id="${run.id}" data-format="${fmt}" data-language="${lng.code}"><img src="${BASE_URL}/public/flags/${lng.flag}" class="me-1" style="width:16px;"> ${fmtLabel}${langData[lng.key] || lng.fallback}</a></li>`);
        });
    });
    return `<div class="dropdown">
        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" title="${escapeHtmlReports(label)}">
            <i class="fa-solid fa-file-export"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">${items.join('')}</ul>
    </div>`;
}

function renderCycleReportTable(type) {
    const reports = cycleReportsByType(type);
    const $table = $(`#tb_cycle_${type}`);
    if ($.fn.DataTable.isDataTable($table)) {
        $table.DataTable().destroy();
        $table.empty();
    }
    const hasData = reports.length > 0 && cycleRuns.length > 0;
    $table.closest('.table-responsive').toggleClass('d-none', !hasData);
    const $empty = $(`#noCycleReports_${type}`).toggleClass('d-none', hasData);
    if (!hasData) {
        const msg = cycleRuns.length === 0
            ? (langData['no_completed_runs'] || 'No completed payroll runs yet.')
            : (langData['no_reports_available'] || 'No reports are registered in this category yet.');
        $empty.find('span').text(msg);
        return;
    }

    const columns = [
        { data: null, title: langData['table_payroll_run'] || 'Payroll Run', render: (d, t, row) => runPeriodLabel(row) },
        { data: 'state', title: langData['table_status'] || 'Status', render: (d) => cycleStateBadge(d) },
    ];
    reports.forEach(function (report) {
        columns.push({
            data: null,
            title: reportLabel(report),
            orderable: false,
            className: 'text-center',
            render: (d, t, row) => cycleExportCellHtml(report, row)
        });
    });
    // 2026-08-28: className:'all' keeps this LAST column (the audit-log action button) from
    // collapsing into the Responsive expand row -- the per-report export columns pushed above stay
    // free to collapse there as normal, only the final action column is protected.
    columns.push({
        data: null,
        title: langData['export_audit_log'] || 'Audit Log',
        orderable: false,
        className: 'text-center all',
        render: (d, t, row) => `<button type="button" class="btn btn-sm btn-link cycle-audit-log-btn" data-run-id="${row.id}" title="${langData['export_audit_log'] || 'Audit Log'}"><i class="fa-solid fa-clock-rotate-left"></i></button>`
    });

    $table.DataTable({
        data: cycleRuns,
        columns: columns,
        order: [], // rows already arrive newest-run-first from the server; keep that order as-is
        responsive: true,
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        drawCallback: function () { getTableLang(); }
    });
}

const cycleTypesInited = { statutory: false, payment: false, internal: false };

/** Guards against the "DataTable(responsive:true) initialized while its Bootstrap tab pane is
 *  display:none collapses every column to 0 width" bug this app has hit repeatedly elsewhere
 *  (see e.g. Employment Certificate Template's/Payslip Template's own list tables) -- only ever
 *  builds a cycle report-type table once BOTH its data is ready AND it's actually visible (its own
 *  pill AND the outer "Per-Cycle Reports" tab both showing), deferring otherwise to whichever
 *  shown.bs.tab handler fires next. */
function ensureCycleTypeInited(type) {
    if (cycleTypesInited[type]) return;
    if (!cycleDataReady.reports || !cycleDataReady.runs) return;
    if (!$('#cycle-pane').hasClass('active')) return;
    renderCycleReportTable(type);
    cycleTypesInited[type] = true;
}

function renderCycleReportTables() {
    const activeType = $('#cycleReportTypeTabs button.active').data('report-type') || 'statutory';
    ensureCycleTypeInited(activeType);
}

function loadCycleRuns() {
    $.ajax({
        url: `${BASE_URL}/api/report.cycle-runs`,
        method: 'GET',
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                cycleRuns = res.data;
                cycleDataReady.runs = true;
                if (cycleDataReady.reports) renderCycleReportTables();
            }
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while loading the data.');
        }
    });
}

$(document).on('shown.bs.tab', '#cycleReportTypeTabs button', function () {
    ensureCycleTypeInited($(this).data('report-type'));
});

$(document).on('click', '.cycle-export-btn', function (e) {
    e.preventDefault();
    const params = new URLSearchParams();
    params.set('report_code', $(this).data('report-code'));
    params.set('format', $(this).data('format'));
    params.set('run_id', $(this).data('run-id'));
    // 2026-08-29, explicit request: "ตอน Export ให้เลือกเพิ่มเติมได้ว่าเอาภาษาไทยหรือภาษาอังกฤษ" -- only
    // present on BANK_TRANSFER_FILE's own language-dropdown items (see cycleExportCellHtml()); a
    // plain export button/format-dropdown item has no data-language attribute at all, so this is a
    // no-op for every other report, same "unused key is simply ignored" pattern
    // ReportsController::generate()'s own context-building already relies on.
    const language = $(this).data('language');
    if (language) params.set('language', language);
    generateReport(`${BASE_URL}/api/report.generate?${params.toString()}`);
});

let cycleExportEmployeeContext = null;
$(document).on('click', '.cycle-export-employee-btn', function () {
    const reportCode = $(this).data('report-code');
    const report = reportList.find(r => r.code === reportCode);
    cycleExportEmployeeContext = { reportCode: reportCode, runId: $(this).data('run-id') };
    $('#cycleExportEmployeeModalTitle').text(report ? reportLabel(report) : (langData['input_employee'] || 'Employee'));
    $('#cycleExportEmployeeSelect').val(null).trigger('change');
    $('#cycleExportEmployeeLanguage').val('th').trigger('change');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('cycleExportEmployeeModal')).show();
});
$(document).on('click', '#cycleExportEmployeeConfirmBtn', function () {
    if (!cycleExportEmployeeContext) return;
    const employeeId = $('#cycleExportEmployeeSelect').val();
    if (!employeeId) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const report = reportList.find(r => r.code === cycleExportEmployeeContext.reportCode);
    const format = (report && report.supported_formats && report.supported_formats[0]) || 'pdf';
    const params = new URLSearchParams();
    params.set('report_code', cycleExportEmployeeContext.reportCode);
    params.set('format', format);
    params.set('run_id', cycleExportEmployeeContext.runId);
    params.set('employee_id', employeeId);
    params.set('language', $('#cycleExportEmployeeLanguage').val() || 'th');
    generateReport(`${BASE_URL}/api/report.generate?${params.toString()}`);
    bootstrap.Modal.getInstance(document.getElementById('cycleExportEmployeeModal')).hide();
});

$(document).on('click', '.cycle-audit-log-btn', function () {
    const runId = $(this).data('run-id');
    const run = cycleRuns.find(r => String(r.id) === String(runId));
    $('#cycleAuditLogRunLabel').text(run ? (run.run_name || run.cycle_name || '') : '');
    $('#cycleAuditLogBody').html(`<div class="text-center text-secondary py-3"><i class="fa-solid fa-spinner fa-spin me-1"></i>${langData['loading'] || 'Loading...'}</div>`);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('cycleAuditLogModal')).show();
    $.ajax({
        url: `${BASE_URL}/api/report.export-logs`,
        method: 'GET',
        dataType: 'json',
        data: { payroll_run_id: runId },
        success: function (res) {
            if (!res.status || !res.data || res.data.length === 0) {
                $('#cycleAuditLogBody').html(`<div class="text-center text-secondary py-3">${langData['no_export_history'] || 'No exports yet for this run.'}</div>`);
                return;
            }
            let html = `<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light text-secondary"><tr>
                    <th>${langData['table_generated_at'] || 'Generated At'}</th>
                    <th>${langData['table_report_name'] || 'Report'}</th>
                    <th>${langData['table_format'] || 'Format'}</th>
                    <th>${langData['table_file_name'] || 'File Name'}</th>
                    <th>${langData['table_generated_by'] || 'Generated By'}</th>
                </tr></thead><tbody>`;
            res.data.forEach(function (row) {
                const by = (currentLang === 'th' ? row.generated_by_name_th : row.generated_by_name_en) || row.generated_by_name_th || row.generated_by_name_en || '-';
                html += `<tr>
                    <td>${formatDisplayDateTime(row.generated_at)}</td>
                    <td>${escapeHtmlReports(reportNameByCode(row.report_code))}</td>
                    <td>${formatLabel(row.format)}</td>
                    <td>${escapeHtmlReports(row.file_name)}</td>
                    <td>${escapeHtmlReports(by)}</td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            $('#cycleAuditLogBody').html(html);
        },
        error: function () {
            $('#cycleAuditLogBody').html(`<div class="text-center text-danger py-3">${langData['save_failed'] || 'An error occurred while loading the data.'}</div>`);
        }
    });
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

$(document).ready(function () {
    loadReportList();
    loadCycleRuns();
    if (typeof initSelect2 === 'function') {
        initSelect2('#filter_export_report_type', { mode: 'static', allowClear: true });
        initSelect2('#cycleExportEmployeeSelect', { mode: 'ajax' });
        initSelect2('#cycleExportEmployeeLanguage', { mode: 'static' });
    }
    // Default the shared Annual year picker to the current B.E. year -- the vast majority of the
    // time an admin opens this tab it's to issue this year's (or the one that just ended's) annual
    // reports, so a blank field with no obvious starting point would just be extra friction.
    const currentBeYear = new Date().getFullYear() + 543;
    $('#reportsPeriodYear').val(currentBeYear);
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
