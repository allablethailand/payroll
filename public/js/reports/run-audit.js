// 2026-08-31, same-day follow-up (item 10, "Design ให้หน่อยครับ No Idea") -- Payroll Run Audit
// diff-history report. List (client-side DataTable, run + origin + edit_count) -> drill-down modal
// (plain semantic table, not a DataTable -- dynamic Edit-N column count per run makes a DataTable
// column definition impractical here, same "not every table needs to be a DataTables instance"
// precedent this app's own breakdown/comparison mini-views already use, e.g.
// payroll/detail.js's breakdownLineRowsRd()).
let runAuditTable = null;
let runAuditDiffModal = null;


function runAuditOriginLabel(origin) {
    const map = {
        sync: langData['run_audit_origin_sync'] || 'Origami Sync',
        cycle: langData['run_audit_origin_cycle'] || 'Cycle',
        manual: langData['run_audit_origin_manual'] || 'Manual/Off-cycle',
    };
    return map[origin] || origin;
}

// 2026-08-31, same-day follow-up, explicit request: "อยากให้เพิ่ม Filter ด้วยครับ และมี pipeline Status
// ให้ด้วยครับ และให้มี Status All ด้วยครับ" -- Date From/To (server-side, same dd/mm/yyyy -> ISO
// conversion every other List page's own filter box already uses) + a pipeline station bar
// (client-side state filter over whatever the date filter already returned, same "load once,
// filter via DataTables ext.search" pattern the Payroll Process List page's own station cards use).
let runAuditCurrentState = 'all';
function toIsoDateRa(displayVal) {
    if (!displayVal) return '';
    const parts = String(displayVal).split('/');
    if (parts.length !== 3) return displayVal;
    const [dd, mm, yyyy] = parts;
    return `${yyyy}-${mm.padStart(2, '0')}-${dd.padStart(2, '0')}`;
}
function updateRunAuditClearFilterVisibility() {
    const hasFilter = !!($('#runAuditFilterDateFrom').val() || $('#runAuditFilterDateTo').val());
    $('#runAuditFilterClearRow').toggleClass('d-none', !hasFilter);
}
function updateRunAuditStationCounts(rows) {
    const counts = { all: rows.length, draft: 0, pending_approval: 0, approved: 0, paid: 0, locked: 0, rejected: 0, need_info: 0, cancelled: 0 };
    rows.forEach(row => { if (counts[row.state] !== undefined) counts[row.state]++; });
    Object.keys(counts).forEach(state => {
        $(`.station-card[data-state="${state}"] .station-count`).text(counts[state]);
    });
}
// 2026-09-02, real bug found and fixed (explicit report: "run-audit.js:55 Uncaught TypeError: Cannot
// read properties of undefined (reading 'ext')") -- this call used to run at PARSE time (top-level,
// not inside $(document).ready()), but this script tag runs BEFORE footer.php's own
// <script src=".../dataTables.js"> tag, so $.fn.dataTable doesn't exist yet when this line executes
// -- same "script tag runs before DataTables itself loads" gotcha payroll/index.js's own
// registerStationSearchFilter() already documents and works around. Wrapped in a function, called
// from the existing $(document).ready() block below (after DataTables has definitely loaded).
function registerRunAuditStationSearchFilter() {
    $.fn.dataTable.ext.search.push(function (settings, searchData, dataIndex, rowData) {
        if (settings.nTable.id !== 'tb_run_audit_list') return true;
        if (runAuditCurrentState === 'all') return true;
        return rowData && rowData.state === runAuditCurrentState;
    });
}
$(document).on('click', '.station-card', function () {
    runAuditCurrentState = $(this).data('state') || 'all';
    $('.station-card').removeClass('active');
    $(this).addClass('active');
    if (runAuditTable) runAuditTable.draw();
});
$(document).on('click', '#runAuditStationFilterToggle', function () {
    const $filter = $('#runAuditStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('changeDate', '#runAuditFilterDateFrom, #runAuditFilterDateTo', function () {
    updateRunAuditClearFilterVisibility();
    if (runAuditTable) runAuditTable.ajax.reload(null, true);
});
$(document).on('click', '#runAuditClearDateFilter', function () {
    $('#runAuditFilterDateFrom, #runAuditFilterDateTo').datepicker('clearDates');
});

function initRunAuditTable() {
    runAuditTable = $('#tb_run_audit_list').DataTable({
        ajax: {
            url: `${BASE_URL}/api/report.run-audit-list`,
            data: function (d) {
                d.date_from = toIsoDateRa($('#runAuditFilterDateFrom').val());
                d.date_to = toIsoDateRa($('#runAuditFilterDateTo').val());
            },
            dataSrc: function (json) {
                updateRunAuditStationCounts(json.data || []);
                return json.data || [];
            },
        },
        columns: [
            { data: 'run_name', render: d => escapeAttr(d) },
            { data: 'cycle_name', render: d => escapeAttr(d || '-') },
            { data: 'origin', render: d => escapeAttr(runAuditOriginLabel(d)) },
            { data: 'state', render: d => escapeAttr(d) },
            { data: null, render: (d, t, row) => `${escapeAttr(row.period_start_date)} - ${escapeAttr(row.period_end_date)}` },
            { data: 'edit_count', className: 'text-end', render: { display: d => Number(d).toLocaleString(), sort: d => Number(d || 0), filter: d => Number(d || 0) } },
            {
                data: null, orderable: false, className: 'text-center', render: (d, t, row) => `
                <button type="button" class="btn btn-sm btn-outline-primary btn-view-run-audit-diff" data-id="${row.id}">
                    <i class="fa-solid fa-magnifying-glass me-1"></i>${langData['view'] || 'View'}
                </button>`,
            },
        ],
        language: getTableLang(),
        initComplete: function () {
            const self = this.api();
            initExcelColumnFilters(self, {
                mode: 'client',
                columns: [
                    { index: 0, key: 'run_name' },
                    { index: 1, key: 'cycle_name' },
                    { index: 2, key: 'origin' },
                    { index: 3, key: 'state' },
                    { index: 5, key: 'edit_count' },
                ],
            });
        },
    });
}

function runAuditLineTypeLabel(lineType) {
    const map = {
        earning_deduction: langData['breakdown_earnings'] || 'Earning/Deduction',
        statutory: langData['sync_line_statutory_badge'] || 'Statutory',
        attendance: langData['run_audit_line_type_attendance'] || 'Attendance',
    };
    return map[lineType] || lineType;
}

function runAuditDiffRowHtml(line, maxEdits) {
    const name = (currentLang === 'th' ? line.employee_name_th : line.employee_name_en) || line.employee_name_th || line.employee_name_en || '';
    let editCells = '';
    for (let i = 0; i < maxEdits; i++) {
        const edit = line.edits[i];
        if (!edit) { editCells += '<td class="text-muted">-</td>'; continue; }
        const who = (currentLang === 'th' ? edit.changed_by_name_th : edit.changed_by_name_en) || edit.changed_by_name_th || '';
        editCells += `<td>
            <div>${fmtNum(edit.new_value)}</div>
            <div class="small text-muted">${escapeAttr(who)}<br>${edit.changed_at ? formatDisplayDateTime(edit.changed_at) : ''}</div>
        </td>`;
    }
    return `<tr>
        <td>${escapeAttr(line.employee_no)} ${escapeAttr(name)}</td>
        <td>${escapeAttr(runAuditLineTypeLabel(line.line_type))}</td>
        <td><code>${escapeAttr(line.item_code)}</code></td>
        <td>${fmtNum(line.original_value)}</td>
        ${editCells}
        <td class="fw-bold">${fmtNum(line.current_value)}</td>
    </tr>`;
}

let runAuditCurrentRunId = null;
function loadRunAuditDiff(runId) {
    runAuditCurrentRunId = runId;
    $.ajax({
        url: `${BASE_URL}/api/report.run-audit-diff`,
        method: 'GET',
        data: { run_id: runId },
        dataType: 'json',
        success: function (res) {
            if (!res.status) { showWarning(res.message || langData['load_failed'] || 'Failed to load data.'); return; }
            const data = res.data || { history_available: false, lines: [] };
            $('#runAuditNoHistoryNotice').toggleClass('d-none', !!data.history_available);
            $('#runAuditEmptyNotice').toggleClass('d-none', !(data.history_available && (data.lines || []).length === 0));
            const lines = data.lines || [];
            let maxEdits = 0;
            lines.forEach(l => { maxEdits = Math.max(maxEdits, l.edits.length); });
            let editHeaders = '';
            for (let i = 1; i <= maxEdits; i++) {
                editHeaders += `<th>${(langData['run_audit_edit_n'] || 'Edit {n}').replace('{n}', i)}</th>`;
            }
            const headHtml = `<table class="table table-sm table-bordered align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th data-i18n="employee">${langData['employee'] || 'Employee'}</th>
                        <th data-i18n="run_audit_line_type">${langData['run_audit_line_type'] || 'Type'}</th>
                        <th data-i18n="item">${langData['item'] || 'Item'}</th>
                        <th data-i18n="run_audit_original">${langData['run_audit_original'] || 'Original'}</th>
                        ${editHeaders}
                        <th data-i18n="run_audit_current">${langData['run_audit_current'] || 'Current'}</th>
                    </tr>
                </thead>
                <tbody>
                    ${lines.map(l => runAuditDiffRowHtml(l, maxEdits)).join('')}
                </tbody>
            </table>`;
            $('#runAuditDiffTableWrap').html(lines.length ? headHtml : '');
        },
        error: function () { showWarning(langData['load_failed'] || 'An error occurred while loading data.'); },
    });
}

$(document).on('click', '.btn-view-run-audit-diff', function () {
    const id = $(this).data('id');
    loadRunAuditDiff(id);
    runAuditDiffModal.show();
});

$(document).on('click', '#runAuditExportBtn', function () {
    if (!runAuditCurrentRunId) return;
    window.open(`${BASE_URL}/api/report.run-audit-export?run_id=${runAuditCurrentRunId}`, '_blank');
});

$(document).ready(function () {
    if (!$('#tb_run_audit_list').length) return;
    runAuditDiffModal = new bootstrap.Modal(document.getElementById('runAuditDiffModal'));
    if (typeof initDatepicker === 'function') {
        initDatepicker('#runAuditFilterDateFrom');
        initDatepicker('#runAuditFilterDateTo');
    }
    registerRunAuditStationSearchFilter();
    initRunAuditTable();
});
