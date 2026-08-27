let reportList = [];

/** Which extra input fields each report code needs. New report = one line here. */
const REPORT_CONTEXT_FIELDS = {
    TH_PND1K_SUMMARY: ['year'],
    TH_PND1: ['run'],
    TH_SSO110: ['run'],
    TH_SSO609: ['year', 'month'],
    TH_KOR20KOR: ['year'],
    TH_SLF: ['run'],
    BANK_TRANSFER_FILE: ['run'],
    PAYMENT_VOUCHER: ['year', 'employee'],
    PAY_SLIP: ['run', 'employee'],
    PAYROLL_REGISTER: ['run'],
};

function reportLabel(report) {
    return (currentLang === 'th' ? report.label.th : report.label.en) || report.label.th || report.label.en || report.code;
}
function formatLabel(fmt) {
    const key = 'format_' + fmt;
    return langData[key] || fmt.toUpperCase();
}

function buildReportCard(report) {
    const tplEl = document.getElementById('reportCardTemplate');
    const clone = tplEl.content.cloneNode(true);
    const wrapper = document.createElement('div');
    wrapper.appendChild(clone);
    const $card = $(wrapper.children);
    $card.find('.report-card-label').text(reportLabel(report));
    const $form = $card.find('.report-generate-form');
    $form.attr('data-report-code', report.code);

    const fields = REPORT_CONTEXT_FIELDS[report.code] || [];
    if (fields.includes('year')) $form.find('.field-year').removeClass('d-none');
    if (fields.includes('month')) $form.find('.field-month').removeClass('d-none');
    if (fields.includes('run')) $form.find('.field-run').removeClass('d-none');
    if (fields.includes('employee')) $form.find('.field-employee').removeClass('d-none');

    const $formatSelect = $form.find('.field-format-input');
    const formatKeys = report.supported_formats.map(f => 'format_' + f);
    $formatSelect.attr('data-report-formats', report.supported_formats.join(','));
    $formatSelect.attr('data-format-keys', formatKeys.join(','));

    return $card;
}

function renderReportCards(reports) {
    const byType = { statutory: [], payment: [], internal: [] };
    reports.forEach(r => { if (byType[r.report_type]) byType[r.report_type].push(r); });

    Object.keys(byType).forEach(type => {
        const $container = $(`#reportCards_${type}`).empty();
        const list = byType[type];
        $(`#noReports_${type}`).toggleClass('d-none', list.length > 0);
        list.forEach(report => {
            $container.append(buildReportCard(report));
        });
    });

    // Init Select2 for every card now that they're in the DOM.
    if (typeof initSelect2 === 'function') {
        $('.field-run-input').each(function () { initSelect2(this, { mode: 'ajax' }); });
        $('.field-employee-input').each(function () { initSelect2(this, { mode: 'ajax' }); });
        $('.field-month-input').each(function () { initSelect2(this, { mode: 'static' }); });
        $('.field-format-input').each(function () {
            const keys = ($(this).attr('data-format-keys') || '').split(',').filter(Boolean);
            const values = ($(this).attr('data-report-formats') || '').split(',').filter(Boolean);
            initSelect2(this, { mode: 'static', keys: keys, values: values });
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
            }
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while loading the data.');
        }
    });
}

function generateReport(url) {
    fetch(url, { method: 'GET' })
        .then(async res => {
            const contentType = res.headers.get('Content-Type') || '';
            if (contentType.indexOf('application/json') !== -1) {
                const data = await res.json();
                showWarning(data.message || langData['generate_failed'] || 'Failed to generate the report.');
                return;
            }
            const disposition = res.headers.get('Content-Disposition') || '';
            const match = disposition.match(/filename="?([^"]+)"?/);
            const fileName = match ? match[1] : 'report';
            const blob = await res.blob();
            const blobUrl = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = blobUrl;
            a.download = fileName;
            document.body.appendChild(a);
            a.click();
            a.remove();
            window.URL.revokeObjectURL(blobUrl);
            showSuccess(langData['generate_success'] || 'Report generated successfully.');
        })
        .catch(function () {
            showWarning(langData['generate_failed'] || 'Failed to generate the report.');
        });
}

$(document).on('submit', '.report-generate-form', function (e) {
    e.preventDefault();
    const $form = $(this);
    const reportCode = $form.attr('data-report-code');

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
    if (!$form.find('.field-year').hasClass('d-none')) {
        params.set('year', $form.find('.field-year-input').val());
    }
    if (!$form.find('.field-month').hasClass('d-none')) {
        params.set('month', $form.find('.field-month-input').val());
    }
    if (!$form.find('.field-run').hasClass('d-none')) {
        params.set('run_id', $form.find('.field-run-input').val());
    }
    if (!$form.find('.field-employee').hasClass('d-none')) {
        params.set('employee_id', $form.find('.field-employee-input').val());
    }

    generateReport(`${BASE_URL}/api/report.generate?${params.toString()}`);
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
    if (typeof initSelect2 === 'function') {
        initSelect2('#filter_export_report_type', { mode: 'static', allowClear: true });
    }
    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
        const tabId = $(e.target).attr('id');
        if (tabId === 'history-tab') {
            initExportHistoryTable();
        }
        $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
    });
});
