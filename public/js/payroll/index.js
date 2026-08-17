let tb_payroll_run;
let tb_pending_sync;
let currentStation = 'draft'; // matches the station card marked .active in the view by default
let selectedPendingSync = {}; // id => row data, for the Pending Pull bulk-select bar

function toIsoDatePr(displayVal) {
    if (!displayVal) return '';
    const parts = String(displayVal).split('/');
    if (parts.length !== 3) return displayVal;
    const [dd, mm, yyyy] = parts;
    return `${yyyy}-${mm.padStart(2, '0')}-${dd.padStart(2, '0')}`;
}
function toDisplayDatePr(isoVal) {
    if (!isoVal) return '';
    const parts = String(isoVal).split('-');
    if (parts.length !== 3) return isoVal;
    const [yyyy, mm, dd] = parts;
    return `${dd}/${mm}/${yyyy}`;
}
function escapeHtmlPr(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}
function fmtNumPr(n) {
    return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function stateBadgePr(state) {
    const map = {
        draft: 'bg-secondary-subtle text-secondary',
        pending_approval: 'bg-warning-subtle text-warning',
        approved: 'bg-info-subtle text-info',
        paid: 'bg-success-subtle text-success',
        locked: 'bg-dark-subtle text-dark',
        rejected: 'bg-danger-subtle text-danger',
        cancelled: 'bg-dark-subtle text-muted',
    };
    const cls = map[state] || 'bg-light text-dark';
    const text = langData['state_' + state] || state;
    return `<span class="badge ${cls}">${text}</span>`;
}
function employeeNamePr(row) {
    return (currentLang === 'th' ? row.created_by_name_th : row.created_by_name_en) || row.created_by_name_th || row.created_by_name_en || '-';
}
function frequencyLabelPr(freq) {
    return (freq && langData['frequency_' + freq]) || freq || '-';
}

// Client-side filter for the Station bar -- runs are fetched unfiltered-by-state (only the Date
// filter hits the server); clicking a station card just re-draws with this filter instead of a
// new request, and also doubles as the source for each card's live count (see
// updateStationCounts()). Scoped to this one table by id so it never affects other DataTables.
// Registered inside $(document).ready() below (not at parse time) -- this script tag runs before
// footer.php's <script src=".../dataTables.js">, so $.fn.dataTable doesn't exist yet up here.
function registerStationSearchFilter() {
    $.fn.dataTable.ext.search.push(function (settings, searchData, dataIndex, rowData) {
        if (settings.nTable.id !== 'tb_payroll_run') return true;
        if (!currentStation || currentStation === 'pending_sync') return true;
        return !!rowData && rowData.state === currentStation;
    });
}

function updateStationCounts() {
    if (!tb_payroll_run) return;
    // { search: 'none' } is required here -- the default row selector only returns rows that pass
    // the *currently active* filter (including our own station search plugin above), so without
    // this every card except the one currently selected would always tally as 0 no matter how many
    // runs actually exist in that state.
    const rows = tb_payroll_run.rows({ search: 'none' }).data().toArray();
    const counts = { draft: 0, pending_approval: 0, approved: 0, paid: 0, locked: 0, rejected: 0, cancelled: 0 };
    rows.forEach(r => { if (Object.prototype.hasOwnProperty.call(counts, r.state)) counts[r.state]++; });
    Object.keys(counts).forEach(state => {
        $(`.station-card[data-state="${state}"] .station-count`).text(counts[state]);
    });
}

function initPayrollRunTable() {
    if ($.fn.DataTable.isDataTable('#tb_payroll_run')) {
        $('#tb_payroll_run').DataTable().ajax.reload(null, false);
        return;
    }
    tb_payroll_run = $('#tb_payroll_run').DataTable({
        responsive: true,
        order: [[1, 'desc']],
        ajax: {
            url: `${BASE_URL}/api/payroll-run.list`,
            dataSrc: 'data',
            data: function (d) {
                d.state = '';
                d.date_from = toIsoDatePr($('#filter_date_from').val());
                d.date_to = toIsoDatePr($('#filter_date_to').val());
            }
        },
        columns: [
            { data: 'run_name', render: d => `<strong class="text-dark">${escapeHtmlPr(d)}</strong>` },
            { data: null, render: (d, t, row) => `${toDisplayDatePr(row.period_start_date)} - ${toDisplayDatePr(row.period_end_date)}` },
            { data: 'state', render: d => stateBadgePr(d) },
            { data: 'employee_count', className: 'text-end' },
            { data: 'total_net_amount', className: 'text-end', render: d => fmtNumPr(d) },
            { data: null, render: (d, t, row) => escapeHtmlPr(employeeNamePr(row)) },
            { data: 'updated_at', render: d => d ? toDisplayDatePr(d.substring(0, 10)) + ' ' + d.substring(11, 16) : '-' },
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            const $wrapper = $(this.api().table().container());
            const $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find('.btn-add-run').length === 0) {
                $searchDiv.append(`
                    <button type="button" class="btn btn-primary ms-1 btn-add-run">
                        <i class="fa-solid fa-plus me-1"></i><span data-i18n="payroll_run">${langData['payroll_run'] || 'Payroll Run'}</span>
                    </button>
                `);
            }
        },
        drawCallback: function () { getTableLang(); updateStationCounts(); }
    });
    $('#tb_payroll_run tbody').off('click', 'tr').on('click', 'tr', function (e) {
        if ($(e.target).closest('.btn-add-run').length) return;
        const rowData = tb_payroll_run.row(this).data();
        if (rowData && rowData.id) {
            window.location.href = `${BASE_URL}/payroll-process/${rowData.id}`;
        }
    });
}

// Lightweight count-only fetch for the Pending Pull card -- used on initial page load and after
// any action that might change it, WITHOUT touching the #tb_pending_sync DataTable itself (which
// stays lazily initialized on first click of that station -- initializing a DataTable while its
// table is still display:none, as it is until then, miscalculates column widths).
function loadPendingSyncCount() {
    $.getJSON(`${BASE_URL}/api/payroll-sync.pending-list`, function (res) {
        const rows = (res && res.data) || [];
        $('.station-card[data-state="pending_sync"] .station-count').text(rows.length);
    });
}

function updateBulkPullBar() {
    const count = Object.keys(selectedPendingSync).length;
    $('#bulkPullCount').text(count);
    $('#bulkPullBar').toggleClass('d-none', count === 0).toggleClass('d-inline-flex', count > 0);
}

function initPendingSyncTable() {
    if ($.fn.DataTable.isDataTable('#tb_pending_sync')) {
        $('#tb_pending_sync').DataTable().ajax.reload(null, false);
        return;
    }
    tb_pending_sync = $('#tb_pending_sync').DataTable({
        responsive: true,
        order: [[7, 'desc']],
        ajax: {
            url: `${BASE_URL}/api/payroll-sync.pending-list`,
            dataSrc: function (json) {
                const rows = json.data || [];
                $('.station-card[data-state="pending_sync"] .station-count').text(rows.length);
                return rows;
            }
        },
        columns: [
            { data: 'id', orderable: false, className: 'text-center', render: d => `<input type="checkbox" class="pending-sync-checkbox" value="${d}">` },
            { data: 'process_no', render: d => `<strong class="text-dark">${escapeHtmlPr(d)}</strong>` },
            { data: 'origami_comp_name', render: d => escapeHtmlPr(d || '-') },
            { data: 'period_name', render: d => escapeHtmlPr(d || '-') },
            { data: 'frequency_type', render: d => escapeHtmlPr(frequencyLabelPr(d)) },
            { data: 'item_count', className: 'text-end' },
            { data: 'unmapped_item_count', className: 'text-end', render: d => Number(d) > 0 ? `<span class="text-danger fw-semibold">${d}</span>` : d },
            { data: 'received_at', render: d => d ? toDisplayDatePr(d.substring(0, 10)) + ' ' + d.substring(11, 16) : '-' },
            {
                data: null, orderable: false, className: 'text-end',
                render: (d, t, row) => `
                    <button type="button" class="btn btn-sm btn-outline-secondary me-1 btn-view-sync" data-id="${row.id}" title="${langData['view'] || 'View'}"><i class="fa-solid fa-eye"></i></button>
                    <button type="button" class="btn btn-sm btn-primary btn-pull-sync" data-id="${row.id}" data-label="${escapeHtmlPr(row.process_no)}"><i class="fa-solid fa-arrow-right-to-bracket me-1"></i><span data-i18n="btn_pull_to_run">${langData['btn_pull_to_run'] || 'Pull to Run'}</span></button>
                `
            },
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            // Relocate the bulk-pull bar (static markup above the table) into the DataTables
            // length control row so the selection count/button sit next to "Show N entries"
            // instead of on their own line -- the bar keeps its d-none/d-inline-flex toggling
            // untouched since this only moves the existing DOM node, not a copy.
            const $wrapper = $(this.api().table().container());
            const $lengthDiv = $wrapper.find('.dt-length');
            if ($lengthDiv.length && $('#bulkPullBar').closest('.dt-length').length === 0) {
                $lengthDiv.append($('#bulkPullBar'));
            }
        },
        drawCallback: function () {
            getTableLang();
            // Restore checked state across redraws (page/search/reload) from the tracked
            // selection, and keep the header checkbox in sync with the current page's rows.
            const $rowBoxes = $('#tb_pending_sync tbody .pending-sync-checkbox');
            $rowBoxes.each(function () {
                $(this).prop('checked', Object.prototype.hasOwnProperty.call(selectedPendingSync, $(this).val()));
            });
            $('#pendingSyncSelectAll').prop('checked', $rowBoxes.length > 0 && $rowBoxes.filter(':not(:checked)').length === 0);
        }
    });
}

function mappingStatusBadgePr(isMapped) {
    return isMapped
        ? `<span class="badge rounded-pill bg-success-subtle text-success"><i class="fa-solid fa-check me-1"></i>${langData['sync_detail_mapped'] || 'Mapped'}</span>`
        : `<span class="badge rounded-pill bg-danger-subtle text-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i>${langData['sync_detail_unmapped'] || 'Unmapped'}</span>`;
}
function syncUnitLabelPr(unitType) {
    if (!unitType) return '';
    const map = {
        count: langData['sync_unit_count'] || 'time(s)',
        hours: langData['sync_unit_hours'] || 'hour(s)',
        days: langData['sync_unit_days'] || 'day(s)',
        minutes: langData['sync_unit_minutes'] || 'minute(s)',
    };
    return map[unitType] || unitType;
}
function syncDetailSectionHeaderPr(num, i18nKey, fallback) {
    return `
        <h6 class="text-secondary fw-bold mb-3 mt-1">
            <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">${num}</label>
            <span>${langData[i18nKey] || fallback}</span>
        </h6>
    `;
}
function renderSyncItemRowPr(item) {
    const isMapped = !!item.matched_employee_no;
    const matchedName = isMapped
        ? `<div class="fw-semibold">${escapeHtmlPr(item.matched_employee_no)}</div><div class="text-muted small">${escapeHtmlPr((currentLang === 'th' ? `${item.matched_name_th} ${item.matched_surname_th}` : `${item.matched_name_en} ${item.matched_surname_en}`).trim())}</div>`
        : `<span class="text-muted">${escapeHtmlPr(item.payroll_code)}</span>`;
    const values = (item.item_values || [])
        .filter(v => Number(v.value) !== 0)
        .map(v => {
            const unitLabel = syncUnitLabelPr(v.unit_type);
            return `<span class="badge bg-light text-dark border me-1 mb-1">${escapeHtmlPr(v.item_code)}: ${escapeHtmlPr(v.value)}${unitLabel ? ` ${escapeHtmlPr(unitLabel)}` : ''}</span>`;
        }).join('') || '<span class="text-muted small">-</span>';
    const otBreakdown = [
        ['sync_ot_working_day', 'Working Day', item.ot_req_working_day_hrs],
        ['sync_ot_day_off', 'Day Off', item.ot_req_weekend_hrs],
        ['sync_ot_holiday', 'Holiday', item.ot_req_holiday_hrs],
    ]
        .filter(([, , hrs]) => Number(hrs || 0) !== 0)
        .map(([key, fallback, hrs]) => `<div class="small text-nowrap"><span class="text-muted">${langData[key] || fallback}:</span> ${escapeHtmlPr(hrs)}</div>`)
        .join('') || `<span class="text-muted small">${item.ot_mins ? escapeHtmlPr(item.ot_mins) + ' ' + (langData['sync_unit_minutes'] || 'minute(s)') : '-'}</span>`;
    const paymentSso = renderPaymentSsoCellPr(item);
    return `
        <tr>
            <td>${mappingStatusBadgePr(isMapped)}</td>
            <td>${matchedName}</td>
            <td>${escapeHtmlPr(item.dept_description || '-')}<br><span class="text-muted small">${escapeHtmlPr(item.position_name || '-')}</span></td>
            <td class="text-end">${escapeHtmlPr(item.working_days ?? '-')}</td>
            <td class="text-end">${escapeHtmlPr(item.absent_days ?? '-')}</td>
            <td class="text-end">${escapeHtmlPr(item.late_mins ?? '-')}</td>
            <td>${otBreakdown}</td>
            <td class="text-end">${escapeHtmlPr(item.trip_allowance ?? '-')}</td>
            <td>${paymentSso}</td>
            <td>${values}</td>
        </tr>
    `;
}
function renderPaymentSsoCellPr(item) {
    let payLine;
    if (item.pay_type === 'transfer') {
        const bankLabel = item.pay_bank_name ? escapeHtmlPr(item.pay_bank_name) : (langData['sync_pay_transfer'] || 'Transfer');
        const maskedNo = item.pay_bank_no_masked ? ` (${escapeHtmlPr(item.pay_bank_no_masked)})` : '';
        payLine = `<div class="small"><i class="fa-solid fa-building-columns text-muted me-1"></i>${bankLabel}${maskedNo}</div>`;
    } else if (item.pay_type === 'cash') {
        payLine = `<div class="small"><i class="fa-solid fa-money-bill text-muted me-1"></i>${langData['sync_pay_cash'] || 'Cash'}</div>`;
    } else {
        payLine = `<div class="small text-muted">-</div>`;
    }
    let ssoBadge;
    if (item.deduct_sso === null || item.deduct_sso === undefined) {
        ssoBadge = `<span class="badge rounded-pill bg-light text-muted border">${langData['sync_sso_not_set'] || 'SSO: Not Set'}</span>`;
    } else if (Number(item.deduct_sso) === 1) {
        ssoBadge = `<span class="badge rounded-pill bg-info-subtle text-info">${langData['sync_sso_deduct'] || 'SSO: Deduct'}</span>`;
    } else {
        ssoBadge = `<span class="badge rounded-pill bg-light text-secondary border">${langData['sync_sso_no_deduct'] || 'SSO: No Deduct'}</span>`;
    }
    return `${payLine}${ssoBadge}`;
}
function renderSyncStatusRowPr(row) {
    return `
        <tr>
            <td>${escapeHtmlPr(row.payroll_code)}</td>
            <td>${escapeHtmlPr(row.emp_name || '-')}</td>
            <td>${escapeHtmlPr(row.dept_description || '-')}<br><span class="text-muted small">${escapeHtmlPr(row.position_name || '-')}</span></td>
            <td>${row.emp_start_date ? toDisplayDatePr(row.emp_start_date) : '-'}</td>
            <td>${row.emp_resign_date ? toDisplayDatePr(row.emp_resign_date) : '-'}</td>
            <td class="text-center">${Number(row.is_new_hire) === 1 ? '<i class="fa-solid fa-circle-check text-success"></i>' : '<span class="text-muted">-</span>'}</td>
            <td class="text-center">${Number(row.is_resigned_this_period) === 1 ? '<i class="fa-solid fa-circle-check text-danger"></i>' : '<span class="text-muted">-</span>'}</td>
            <td>${escapeHtmlPr(row.status_text || '-')}</td>
        </tr>
    `;
}
function syncSummaryFieldPr(icon, i18nKey, fallback, value) {
    return `
        <div class="col-sm-4 col-lg-3">
            <div class="sync-summary-field">
                <div class="sync-summary-icon"><i class="fa-solid ${icon}"></i></div>
                <div>
                    <div class="text-muted small">${langData[i18nKey] || fallback}</div>
                    <div class="fw-bold">${value}</div>
                </div>
            </div>
        </div>
    `;
}
function renderSyncDetail(data) {
    const items = data.items || [];
    const statusRows = data.employee_status || [];
    const receivedAt = data.received_at ? toDisplayDatePr(data.received_at.substring(0, 10)) + ' ' + data.received_at.substring(11, 16) : '-';
    const unmapped = Number(data.unmapped_item_count) || 0;
    const html = `
        <div class="sync-summary-card row g-3 mb-4">
            ${syncSummaryFieldPr('fa-hashtag', 'table_process_no', 'Process No', escapeHtmlPr(data.process_no))}
            ${syncSummaryFieldPr('fa-building', 'table_comp_name', 'Company', escapeHtmlPr(data.origami_comp_name))}
            ${syncSummaryFieldPr('fa-calendar-days', 'table_period', 'Pay Period', escapeHtmlPr(data.period_name || '-'))}
            ${syncSummaryFieldPr('fa-repeat', 'table_frequency', 'Frequency', escapeHtmlPr(frequencyLabelPr(data.frequency_type)))}
            ${syncSummaryFieldPr('fa-users', 'table_employee_count', 'Employees', escapeHtmlPr(data.item_count))}
            ${syncSummaryFieldPr('fa-triangle-exclamation', 'table_unmapped', 'Unmapped', unmapped > 0 ? `<span class="text-danger">${unmapped}</span>` : unmapped)}
            ${syncSummaryFieldPr('fa-clock', 'table_received_at', 'Received', receivedAt)}
        </div>
        ${syncDetailSectionHeaderPr(1, 'sync_detail_items_section', 'Employee Attendance Data')}
        <div class="table-responsive mb-4 sync-detail-table">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>${langData['sync_detail_mapped'] || 'Mapped'}</th>
                        <th>${langData['table_matched_employee'] || 'Matched Employee'}</th>
                        <th>${langData['table_dept_position'] || 'Dept / Position'}</th>
                        <th class="text-end">${langData['table_working_days'] || 'Working Days'}</th>
                        <th class="text-end">${langData['table_absent_days'] || 'Absent Days'}</th>
                        <th class="text-end">${langData['table_late_mins'] || 'Late (min)'}</th>
                        <th>${langData['table_ot_breakdown'] || 'OT (hrs)'}</th>
                        <th class="text-end">${langData['table_trip_allowance'] || 'Trip Allowance'}</th>
                        <th>${langData['table_payment_sso'] || 'Payment / SSO'}</th>
                        <th>${langData['table_item_values'] || 'Items'}</th>
                    </tr>
                </thead>
                <tbody>${items.length ? items.map(renderSyncItemRowPr).join('') : `<tr><td colspan="10" class="text-center text-muted py-3">-</td></tr>`}</tbody>
            </table>
        </div>
        ${syncDetailSectionHeaderPr(2, 'sync_detail_status_section', 'Employee Status Snapshot')}
        <div class="table-responsive sync-detail-table">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>${langData['table_payroll_code'] || 'Payroll Code'}</th>
                        <th>${langData['table_matched_employee'] || 'Matched Employee'}</th>
                        <th>${langData['table_dept_position'] || 'Dept / Position'}</th>
                        <th>${langData['table_start_date'] || 'Start Date'}</th>
                        <th>${langData['table_resign_date'] || 'Resign Date'}</th>
                        <th class="text-center">${langData['table_new_hire'] || 'New Hire'}</th>
                        <th class="text-center">${langData['table_resigned_this_period'] || 'Resigned'}</th>
                        <th>${langData['table_status_text'] || 'Status'}</th>
                    </tr>
                </thead>
                <tbody>${statusRows.length ? statusRows.map(renderSyncStatusRowPr).join('') : `<tr><td colspan="8" class="text-center text-muted py-3">-</td></tr>`}</tbody>
            </table>
        </div>
    `;
    $('#pendingSyncViewBody').html(html);
}

function resetRunForm() {
    $('#payrollRunForm')[0].reset();
    $('.is-invalid').removeClass('is-invalid');
    $('#run_cycle_id').val('').trigger('change');
    $('#run_sync_process_id').val('');
}
function validateRunForm() {
    let firstInvalid = null;
    $('#payrollRunModal .required').each(function () {
        const $el = $(this);
        const value = ($el.val() || '').toString().trim();
        if (!value) {
            $el.addClass('is-invalid');
            if (!firstInvalid) firstInvalid = $el;
        } else {
            $el.removeClass('is-invalid');
        }
    });
    return firstInvalid;
}
function collectRunFormData() {
    return {
        cycle_id: $('#run_cycle_id').val(),
        run_name: $('#run_name').val().trim(),
        period_start_date: toIsoDatePr($('#run_period_start').val()),
        period_end_date: toIsoDatePr($('#run_period_end').val()),
        payment_date: toIsoDatePr($('#run_payment_date').val()),
        notes: $('#run_notes').val().trim(),
        sync_process_id: $('#run_sync_process_id').val() || null,
    };
}

function showStation(state) {
    currentStation = state;
    $('.station-card').removeClass('active');
    $(`.station-card[data-state="${state}"]`).addClass('active');
    if (state === 'pending_sync') {
        $('#tb_payroll_run_wrapper').addClass('d-none');
        // The <table> itself starts with d-none in the markup (hidden until first shown) -- once
        // DataTables wraps it, the wrapper controls visibility, but the table's own d-none never
        // gets cleared unless we do it here explicitly (a hidden wrapper's visible child is still
        // hidden, but a visible wrapper's d-none child stays hidden too).
        $('#tb_pending_sync').removeClass('d-none');
        $('#tb_pending_sync_wrapper').removeClass('d-none');
        initPendingSyncTable();
    } else {
        $('#tb_pending_sync_wrapper').addClass('d-none');
        $('#tb_payroll_run_wrapper').removeClass('d-none');
        if (tb_payroll_run) tb_payroll_run.draw();
    }
}

$(document).on('click', '.station-card', function () {
    showStation($(this).data('state') || '');
});
$(document).on('click', '#stationFilterToggle', function () {
    const $filter = $('#stationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('change', '#filter_date_from, #filter_date_to', function () {
    if (tb_payroll_run) tb_payroll_run.ajax.reload(null, true);
});
$(document).on('click', '.btn-add-run', function () {
    resetRunForm();
    new bootstrap.Modal(document.getElementById('payrollRunModal')).show();
});
$(document).on('click', '.btn-pull-sync', function () {
    resetRunForm();
    $('#run_sync_process_id').val($(this).data('id'));
    $('#run_name').val($(this).data('label'));
    new bootstrap.Modal(document.getElementById('payrollRunModal')).show();
});
$(document).on('click', '.btn-view-sync', function () {
    $('#pendingSyncViewBody').html(`<div class="text-center text-muted py-4"><i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['loading'] || 'Loading...'}</span></div>`);
    new bootstrap.Modal(document.getElementById('pendingSyncViewModal')).show();
    $.getJSON(`${BASE_URL}/api/payroll-sync.pending-get`, { id: $(this).data('id') })
        .done(function (res) {
            if (res.status) {
                renderSyncDetail(res.data);
            } else {
                $('#pendingSyncViewBody').html(`<div class="text-danger">${escapeHtmlPr(res.message || 'Error')}</div>`);
            }
        })
        .fail(function () {
            $('#pendingSyncViewBody').html(`<div class="text-danger">${langData['save_failed'] || 'An error occurred while saving the data.'}</div>`);
        });
});
$(document).on('change', '.pending-sync-checkbox', function () {
    const id = $(this).val();
    if (this.checked) {
        selectedPendingSync[id] = tb_pending_sync.row($(this).closest('tr')).data();
    } else {
        delete selectedPendingSync[id];
    }
    const $boxes = $('#tb_pending_sync tbody .pending-sync-checkbox');
    $('#pendingSyncSelectAll').prop('checked', $boxes.length > 0 && $boxes.filter(':not(:checked)').length === 0);
    updateBulkPullBar();
});
$(document).on('change', '#pendingSyncSelectAll', function () {
    $('#tb_pending_sync tbody .pending-sync-checkbox').prop('checked', this.checked).trigger('change');
});
$(document).on('click', '#btnBulkPull', function () {
    const ids = Object.keys(selectedPendingSync);
    if (ids.length === 0) return;
    const $rows = $('#bulkPullRows').empty();
    ids.forEach(function (id) {
        const row = selectedPendingSync[id];
        $rows.append(`
            <div class="border rounded p-3 mb-3 bulk-pull-row" data-process-id="${id}">
                <div class="fw-bold mb-2">${escapeHtmlPr(row.process_no)} <span class="text-muted small">(${escapeHtmlPr(row.period_name || '-')})</span></div>
                <div class="row g-2">
                    <div class="col-sm-4">
                        <label class="form-label mb-1">${langData['modal_cycle'] || 'Payroll Cycle'} <span class="text-danger">*</span></label>
                        <select class="form-select select2-remote bulk-cycle-select required" data-api="/api/payroll-cycle.options"></select>
                    </div>
                    <div class="col-sm-8">
                        <label class="form-label mb-1">${langData['modal_run_name'] || 'Run Name'} <span class="text-danger">*</span></label>
                        <input type="text" class="form-control bulk-run-name required" value="${escapeHtmlPr(row.process_no)}">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label mb-1">${langData['modal_period_start'] || 'Period Start Date'} <span class="text-danger">*</span></label>
                        <div class="input-group"><input type="text" class="form-control datepicker bulk-period-start required" autocomplete="off"><span class="input-group-text"><i class="fas fa-calendar"></i></span></div>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label mb-1">${langData['modal_period_end'] || 'Period End Date'} <span class="text-danger">*</span></label>
                        <div class="input-group"><input type="text" class="form-control datepicker bulk-period-end required" autocomplete="off"><span class="input-group-text"><i class="fas fa-calendar"></i></span></div>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label mb-1">${langData['modal_payment_date'] || 'Payment Date'} <span class="text-danger">*</span></label>
                        <div class="input-group"><input type="text" class="form-control datepicker bulk-payment-date required" autocomplete="off"><span class="input-group-text"><i class="fas fa-calendar"></i></span></div>
                    </div>
                </div>
                <div class="bulk-pull-row-status mt-2"></div>
            </div>
        `);
    });
    if (typeof initSelect2 === 'function') initSelect2('.bulk-cycle-select', { mode: 'ajax' });
    if (typeof initDatepicker === 'function') initDatepicker('.bulk-period-start, .bulk-period-end, .bulk-payment-date');
    new bootstrap.Modal(document.getElementById('bulkPullModal')).show();
});
$(document).on('click', '#btnBulkPullSubmit', function () {
    const $rows = $('.bulk-pull-row');
    let hasInvalid = false;
    $rows.find('.required').each(function () {
        const $el = $(this);
        const val = ($el.val() || '').toString().trim();
        $el.toggleClass('is-invalid', !val);
        if (!val) hasInvalid = true;
    });
    if (hasInvalid) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const $btn = $(this).prop('disabled', true);
    const rowEls = $rows.toArray();
    let successCount = 0;
    let failCount = 0;
    function processNext(i) {
        if (i >= rowEls.length) {
            $btn.prop('disabled', false);
            const summary = `${successCount} ${langData['bulk_pull_result_success'] || 'created'}, ${failCount} ${langData['bulk_pull_result_failed'] || 'failed'}`;
            if (failCount === 0) {
                showSuccess(summary);
                bootstrap.Modal.getInstance(document.getElementById('bulkPullModal')).hide();
            } else {
                showWarning(summary);
            }
            selectedPendingSync = {};
            updateBulkPullBar();
            if (tb_payroll_run) tb_payroll_run.ajax.reload(null, false);
            if (tb_pending_sync) {
                tb_pending_sync.ajax.reload(null, false);
            } else {
                loadPendingSyncCount();
            }
            return;
        }
        const $row = $(rowEls[i]);
        const payload = {
            cycle_id: $row.find('.bulk-cycle-select').val(),
            run_name: $row.find('.bulk-run-name').val().trim(),
            period_start_date: toIsoDatePr($row.find('.bulk-period-start').val()),
            period_end_date: toIsoDatePr($row.find('.bulk-period-end').val()),
            payment_date: toIsoDatePr($row.find('.bulk-payment-date').val()),
            sync_process_id: $row.data('process-id'),
        };
        $.ajax({
            url: `${BASE_URL}/api/payroll-run.save`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify(payload),
            success: function (res) {
                if (res.status) {
                    successCount++;
                    $row.find('.bulk-pull-row-status').html(`<span class="text-success small"><i class="fa-solid fa-check me-1"></i>${langData['bulk_pull_result_success'] || 'created'}</span>`);
                } else {
                    failCount++;
                    $row.find('.bulk-pull-row-status').html(`<span class="text-danger small"><i class="fa-solid fa-xmark me-1"></i>${escapeHtmlPr(res.message || 'failed')}</span>`);
                }
                processNext(i + 1);
            },
            error: function () {
                failCount++;
                $row.find('.bulk-pull-row-status').html(`<span class="text-danger small">${langData['save_failed'] || 'An error occurred.'}</span>`);
                processNext(i + 1);
            }
        });
    }
    processNext(0);
});
$(document).on('submit', '#payrollRunForm', function (e) {
    e.preventDefault();
    const invalidEl = validateRunForm();
    if (invalidEl) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const payload = collectRunFormData();
    const $btn = $('#payrollRunForm button[type="submit"]');
    const originalHtml = $btn.html();
    $btn.prop('disabled', true).html(`<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['saving'] || 'Saving...'}</span>`);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.save`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(payload),
        success: function (res) {
            $btn.prop('disabled', false).html(originalHtml);
            if (typeof updateText === 'function') updateText($btn[0]);
            if (res.status) {
                showSuccess(langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('payrollRunModal')).hide();
                if (tb_payroll_run) tb_payroll_run.ajax.reload(null, false);
                if (tb_pending_sync) {
                    tb_pending_sync.ajax.reload(null, false);
                } else {
                    loadPendingSyncCount();
                }
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            $btn.prop('disabled', false).html(originalHtml);
            if (typeof updateText === 'function') updateText($btn[0]);
            showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
        }
    });
});

$(document).ready(function () {
    registerStationSearchFilter();
    initPayrollRunTable();
    loadPendingSyncCount();
    if (typeof initSelect2 === 'function') {
        initSelect2('#run_cycle_id', { mode: 'ajax' });
    }
    if (typeof initDatepicker === 'function') {
        initDatepicker('#filter_date_from');
        initDatepicker('#filter_date_to');
        initDatepicker('#run_period_start');
        initDatepicker('#run_period_end');
        initDatepicker('#run_payment_date');
    }
});
