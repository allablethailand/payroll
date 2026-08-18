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
// Actions column for tb_payroll_run, rendered as one Bootstrap button-group (per explicit
// request). Edit/View are now a SINGLE merged button (per explicit request) -- both just
// navigate to the Detail page in a NEW tab using public_id (the IdCodec-encoded token, see
// PayrollController::list(), never the raw numeric id); only the icon/label/tooltip differ by
// state (pencil "Edit" for draft, eye "View" for anything else), since PayrollRunModel::update()
// itself only allows editing a draft run anyway -- actual editing happens on the Detail page's
// own edit modal, not a separate one here. Delete only makes sense for draft
// (PayrollRunModel::delete() rejects any other state); Cancel for anything not yet paid (matches
// PayrollRunModel::cancel()'s own allowed-state check).
function renderRunActionsPr(row) {
    const isDraft = row.state === 'draft';
    let html = '<div class="btn-group border rounded-3 bg-white row-actions" role="group">';
    html += `<a href="${BASE_URL}/payroll-process/${row.public_id}" target="_blank" rel="noopener" class="btn ${isDraft ? 'btn-link text-warning' : 'btn-link text-info'}" title="${langData[isDraft ? 'action_edit' : 'view'] || (isDraft ? 'Edit' : 'View')}"><i class="fa-solid ${isDraft ? 'fa-pen-to-square' : 'fa-eye'}"></i></a>`;
    if (['draft', 'pending_approval', 'approved', 'rejected'].includes(row.state)) {
        html += `<button type="button" class="btn btn-link text-danger border-start btn-cancel-run" data-id="${row.id}" title="${langData['action_cancel'] || 'Cancel'}"><i class="fa-solid fa-ban"></i></button>`;
    }
    if (isDraft) {
        html += `<button type="button" class="btn btn-link text-danger border-start btn-delete-run" data-id="${row.id}" title="${langData['action_delete'] || 'Delete'}"><i class="fa-solid fa-trash-alt"></i></button>`;
    }
    html += '</div>';
    return html;
}
// Cancelling/deleting a run pulled from Origami sync returns its source process to the Pending
// Pull station (PayrollRunModel::cancel()/delete() clear sync_process_id) -- refresh that station
// too after either action succeeds, not just the main runs table, so it doesn't look stale.
function refreshAfterRunMutation() {
    if (tb_payroll_run) tb_payroll_run.ajax.reload(null, false);
    if (tb_pending_sync) {
        tb_pending_sync.ajax.reload(null, false);
    } else {
        loadPendingSyncCount();
    }
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
            { data: null, className: 'text-center', orderable: false, render: (d, t, row) => renderRunActionsPr(row) },
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
        if ($(e.target).closest('.row-actions').length) return;
        const rowData = tb_payroll_run.row(this).data();
        if (rowData && rowData.public_id) {
            window.location.href = `${BASE_URL}/payroll-process/${rowData.public_id}`;
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
            data: function (d) {
                d.date_from = toIsoDatePr($('#filter_date_from').val());
                d.date_to = toIsoDatePr($('#filter_date_to').val());
            },
            dataSrc: function (json) {
                const rows = json.data || [];
                $('.station-card[data-state="pending_sync"] .station-count').text(rows.length);
                return rows;
            }
        },
        columns: [
            { data: 'id', orderable: false, className: 'text-center', render: d => `<input type="checkbox" class="pending-sync-checkbox" value="${d}">` },
            { data: 'process_no', render: d => `<strong class="text-dark">${escapeHtmlPr(d)}</strong>` },
            { data: 'period_name', render: d => escapeHtmlPr(d || '-') },
            { data: 'frequency_type', render: d => escapeHtmlPr(frequencyLabelPr(d)) },
            { data: 'item_count', className: 'text-end' },
            { data: 'unmapped_item_count', className: 'text-end', render: d => Number(d) > 0 ? `<span class="text-danger fw-semibold">${d}</span>` : d },
            { data: 'received_at', render: d => d ? toDisplayDatePr(d.substring(0, 10)) + ' ' + d.substring(11, 16) : '-' },
            {
                data: null, orderable: false, className: 'text-center',
                render: (d, t, row) => `
                    <div class="btn-group rounded-3 row-actions" role="group">
                        <button type="button" class="btn btn-warning btn-pull-sync" data-id="${row.id}" data-label="${escapeHtmlPr(row.process_no)}" title="${langData['btn_pull_to_run'] || 'Pull to Run'}"><i class="fa-solid fa-arrow-right-to-bracket me-1"></i><span data-i18n="btn_pull_to_run">${langData['btn_pull_to_run'] || 'Pull to Run'}</span></button>
                        <button type="button" class="btn btn-outline-info btn-view-sync" data-id="${row.id}" title="${langData['view'] || 'View'}"><i class="fa-solid fa-eye"></i></button>
                    </div>
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
// Per-employee CARD, not a table row -- 11 columns of mixed badges/stacked-lines/small-text
// squeezed into one wide table row was the core complaint ("too dense, too many columns, no
// clear direction"), and no amount of border/stripe styling on a table fixes a structural
// density problem. A card per employee lets each attribute get its own labeled slot instead of
// fighting for horizontal space, and color is now used ONLY for status meaning (mapped/unmapped,
// SSO) -- every purely decorative icon (bank, id-card) stays neutral text-muted so color always
// means something specific instead of just decorating.
function renderSyncItemCardPr(item) {
    const isMapped = !!item.matched_employee_no;
    const nameLine = isMapped
        ? `<span class="fw-semibold">${escapeHtmlPr(item.matched_employee_no)}</span> <span class="text-muted">— ${escapeHtmlPr((currentLang === 'th' ? `${item.matched_name_th} ${item.matched_surname_th}` : `${item.matched_name_en} ${item.matched_surname_en}`).trim())}</span>`
        : `<span class="text-muted">${escapeHtmlPr(item.payroll_code)}</span>`;
    const values = (item.item_values || [])
        .filter(v => Number(v.value) !== 0)
        .map(v => {
            const unitLabel = syncUnitLabelPr(v.unit_type);
            return `<span class="badge bg-light text-dark border me-1 mb-1">${escapeHtmlPr(v.item_code)}: ${escapeHtmlPr(v.value)}${unitLabel ? ` ${escapeHtmlPr(unitLabel)}` : ''}</span>`;
        }).join('');
    const otBreakdown = [
        ['sync_ot_working_day', 'Working Day', item.ot_req_working_day_hrs],
        ['sync_ot_day_off', 'Day Off', item.ot_req_weekend_hrs],
        ['sync_ot_holiday', 'Holiday', item.ot_req_holiday_hrs],
    ]
        .filter(([, , hrs]) => Number(hrs || 0) !== 0)
        .map(([key, fallback, hrs]) => `${langData[key] || fallback} ${escapeHtmlPr(hrs)}h`)
        .join(' · ') || (item.ot_mins ? `${escapeHtmlPr(item.ot_mins)} ${langData['sync_unit_minutes'] || 'minute(s)'}` : '-');
    return `
        <div class="sync-emp-card${isMapped ? '' : ' sync-emp-card-unmapped'}">
            <div class="sync-emp-card-header">
                <div class="sync-emp-card-identity">
                    ${mappingStatusBadgePr(isMapped)}
                    <span class="sync-emp-card-name">${nameLine}</span>
                </div>
                <div class="sync-emp-card-dept">${escapeHtmlPr(item.dept_description || '-')} <span class="text-muted">/ ${escapeHtmlPr(item.position_name || '-')}</span></div>
            </div>
            <div class="sync-emp-stats">
                <div class="sync-emp-stat"><span class="sync-emp-stat-label">${langData['table_working_days'] || 'Working Days'}</span><span class="sync-emp-stat-value">${escapeHtmlPr(item.working_days ?? '-')}</span></div>
                <div class="sync-emp-stat"><span class="sync-emp-stat-label">${langData['table_absent_days'] || 'Absent Days'}</span><span class="sync-emp-stat-value">${escapeHtmlPr(item.absent_days ?? '-')}</span></div>
                <div class="sync-emp-stat"><span class="sync-emp-stat-label">${langData['table_late_mins'] || 'Late (min)'}</span><span class="sync-emp-stat-value">${escapeHtmlPr(item.late_mins ?? '-')}</span></div>
                <div class="sync-emp-stat"><span class="sync-emp-stat-label">${langData['table_ot_breakdown'] || 'OT (hrs)'}</span><span class="sync-emp-stat-value">${otBreakdown}</span></div>
                <div class="sync-emp-stat"><span class="sync-emp-stat-label">${langData['table_trip_allowance'] || 'Trip Allowance'}</span><span class="sync-emp-stat-value">${escapeHtmlPr(item.trip_allowance ?? '-')}</span></div>
            </div>
            <div class="sync-emp-card-footer">
                <span class="sync-emp-card-payment">${renderPaymentSsoCellPr(item)}</span>
                <span class="sync-emp-card-idcard">${renderIdCardCellPr(item)}</span>
                ${values ? `<span class="sync-emp-card-items">${values}</span>` : ''}
            </div>
        </div>
    `;
}
function renderIdCardCellPr(item) {
    if (!item.id_card_no_masked) {
        return `<span class="text-muted">-</span>`;
    }
    const expire = item.id_card_expire_date
        ? ` <span class="text-muted">(${langData['id_card_expire'] || 'ID Card Expire Date'}: ${toDisplayDatePr(item.id_card_expire_date)})</span>`
        : '';
    return `<span><i class="fa-solid fa-id-card text-muted me-1"></i>${escapeHtmlPr(item.id_card_no_masked)}</span>${expire}`;
}
function renderPaymentSsoCellPr(item) {
    let payLine;
    if (item.pay_type === 'transfer') {
        const bankLabel = item.pay_bank_name ? escapeHtmlPr(item.pay_bank_name) : (langData['sync_pay_transfer'] || 'Transfer');
        const maskedNo = item.pay_bank_no_masked ? ` (${escapeHtmlPr(item.pay_bank_no_masked)})` : '';
        payLine = `<i class="fa-solid fa-building-columns text-muted me-1"></i>${bankLabel}${maskedNo}`;
    } else if (item.pay_type === 'cash') {
        payLine = `<i class="fa-solid fa-money-bill text-muted me-1"></i>${langData['sync_pay_cash'] || 'Cash'}`;
    } else {
        payLine = `<span class="text-muted">-</span>`;
    }
    let ssoBadge;
    if (item.deduct_sso === null || item.deduct_sso === undefined) {
        ssoBadge = `<span class="badge rounded-pill bg-light text-muted border">${langData['sync_sso_not_set'] || 'SSO: Not Set'}</span>`;
    } else if (Number(item.deduct_sso) === 1) {
        ssoBadge = `<span class="badge rounded-pill bg-info-subtle text-info">${langData['sync_sso_deduct'] || 'SSO: Deduct'}</span>`;
    } else {
        ssoBadge = `<span class="badge rounded-pill bg-light text-secondary border">${langData['sync_sso_no_deduct'] || 'SSO: No Deduct'}</span>`;
    }
    return `<span>${payLine}</span> ${ssoBadge}`;
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
        <div class="detail-section mb-4">
            ${syncDetailSectionHeaderPr(1, 'sync_detail_items_section', 'Employee Attendance Data')}
            <div class="sync-emp-card-list">
                ${items.length ? items.map(renderSyncItemCardPr).join('') : `<div class="text-center text-muted py-3">-</div>`}
            </div>
        </div>
        <div class="detail-section">
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
        </div>
    `;
    $('#pendingSyncViewBody').html(html);
}

function resetRunForm() {
    $('#payrollRunForm')[0].reset();
    $('.is-invalid').removeClass('is-invalid');
    $('#run_cycle_id').val('').trigger('change');
    $('#run_sync_process_id').val('');
    $('#run_is_offcycle').prop('checked', false);
    $('#run_offcycle_row').removeClass('d-none');
    $('#run_purpose').val('payroll').trigger('change');
    $('#run_compute_statutory').prop('checked', true);
    setOffCycleMode(false);
}
// Off-cycle runs (e.g. an out-of-cycle payment) skip the Payroll Cycle field entirely -- per
// explicit request. Only offered on the standalone "Add" flow; Pull-to-run hides the toggle
// entirely (that data is inherently cycle-based) via #run_offcycle_row.addClass('d-none').
function setOffCycleMode(isOffCycle) {
    $('#run_cycle_row').toggleClass('d-none', isOffCycle);
    $('#run_cycle_id').toggleClass('required', !isOffCycle);
    if (isOffCycle) {
        $('#run_cycle_id').val('').trigger('change');
        $('#run_cycle_id').removeClass('is-invalid');
    }
    // Period Start/End are only required for a cycle-based run -- an off-cycle run (e.g. a
    // special bonus payout) doesn't always have a meaningful attendance period, per explicit
    // request. Payment Date stays required either way -- toggled independently, never touched
    // here. #run_period_required_mark is the red "*" next to the Period Start/End label only
    // (Payment Date has its own separate, always-shown "*").
    $('#run_period_start, #run_period_end').toggleClass('required', !isOffCycle);
    $('#run_period_required_mark').toggleClass('d-none', isOffCycle);
    if (isOffCycle) {
        $('#run_period_start, #run_period_end').removeClass('is-invalid');
    }
    // Run Purpose (Payroll / Incentive-Other Payment) only makes sense for a genuine off-cycle
    // run, per explicit request (2026-08-19) -- PayrollRunModel::create() rejects run_purpose=
    // 'incentive' outright whenever a cycle is selected, so hiding it here just keeps the form
    // from offering a choice the backend would reject anyway.
    $('#run_purpose_row').toggleClass('d-none', !isOffCycle);
    if (!isOffCycle) {
        $('#run_purpose').val('payroll').trigger('change');
    }
}
// Compute Statutory only matters (and only shows) once Incentive/Other Payment is actually
// selected -- a normal Payroll run always computes it, no choice to offer.
function updateComputeStatutoryVisibility() {
    $('#run_compute_statutory_row').toggleClass('d-none', $('#run_purpose').val() !== 'incentive');
}
// Auto-fills Period Start/End/Payment Date from the selected cycle's own configured cutoff/
// payment day settings, per explicit request -- pure convenience default, every field stays
// editable afterward. Silently does nothing on failure (cycle not fully configured, network
// error, etc.) so manual entry always still works as a fallback.
function applySuggestedPeriod(cycleId) {
    if (!cycleId) {
        return;
    }
    $.ajax({
        url: `${BASE_URL}/api/payroll-cycle.suggest-period`,
        method: 'GET',
        data: { id: cycleId },
        dataType: 'json',
        success: function (res) {
            if (!res.status) {
                return;
            }
            $('#run_period_start').val(toDisplayDatePr(res.period_start_date));
            $('#run_period_end').val(toDisplayDatePr(res.period_end_date));
            $('#run_payment_date').val(toDisplayDatePr(res.payment_date));
            $('#run_period_start, #run_period_end, #run_payment_date').removeClass('is-invalid');
        }
    });
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
    const isOffCycle = $('#run_is_offcycle').is(':checked');
    const runPurpose = isOffCycle ? ($('#run_purpose').val() || 'payroll') : 'payroll';
    return {
        cycle_id: isOffCycle ? null : $('#run_cycle_id').val(),
        run_purpose: runPurpose,
        compute_statutory: runPurpose === 'incentive' && $('#run_compute_statutory').is(':checked') ? 1 : 0,
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
// bootstrap-datepicker's core _setDate() fires BOTH 'changeDate' and the native 'change' event
// together, unconditionally, for every date-picked interaction (confirmed in the bundled
// library's own source) -- binding to both (an earlier fix here) double-fired this handler,
// causing two back-to-back ajax.reload() calls per pick (visible in Network as one cancelled
// request immediately followed by one 200). 'changeDate' alone is reliable on its own since it's
// the one _setDate() always fires regardless of code path (clicking a day, clearDates(), etc.).
// Clear Filter only makes sense (and only shows) once at least one of the two fields actually has
// a value -- per explicit request, hidden by default rather than always visible.
function updateClearFilterVisibility() {
    const hasFilter = !!($('#filter_date_from').val() || $('#filter_date_to').val());
    $('#btnClearDateFilter').toggleClass('d-none', !hasFilter);
}
$(document).on('changeDate', '#filter_date_from, #filter_date_to', function () {
    updateClearFilterVisibility();
    if (tb_payroll_run) tb_payroll_run.ajax.reload(null, true);
    // Also reload the Pending Pull ("Wait") table -- its own ajax now sends the same date_from/
    // date_to (filtered on received_at, its only real date field -- payroll_sync_processes has no
    // period_start/end of its own). Blindly reloading it unfiltered here used to make a genuinely
    // empty result on the main table look like "the filter gave up and fetched everything", since
    // this table would always come back full regardless of the date picked -- per explicit
    // feedback, a filter that matches nothing should just show nothing, not fall back to showing
    // everything.
    if (tb_pending_sync) tb_pending_sync.ajax.reload(null, true);
});
$(document).on('click', '#btnClearDateFilter', function () {
    // .datepicker('clearDates') goes through the same library API used to set them, so it fires
    // 'changeDate' itself and the handler above reloads both tables (and re-hides this button)
    // automatically -- no need to duplicate that here.
    $('#filter_date_from, #filter_date_to').datepicker('clearDates');
});
$(document).on('click', '.btn-add-run', function () {
    resetRunForm();
    new bootstrap.Modal(document.getElementById('payrollRunModal')).show();
});
$(document).on('click', '.btn-pull-sync', function () {
    resetRunForm();
    // Pulling from a sync process is inherently cycle-based data -- the off-cycle option doesn't
    // apply here, so hide it entirely rather than just leaving it unchecked.
    $('#run_offcycle_row').addClass('d-none');
    $('#run_sync_process_id').val($(this).data('id'));
    $('#run_name').val($(this).data('label'));
    new bootstrap.Modal(document.getElementById('payrollRunModal')).show();
});
$(document).on('change', '#run_is_offcycle', function () {
    setOffCycleMode($(this).is(':checked'));
});
$(document).on('change', '#run_cycle_id', function () {
    applySuggestedPeriod($(this).val());
});
$(document).on('change', '#run_purpose', function () {
    updateComputeStatutoryVisibility();
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
    let remappedTotal = 0;
    let placeholdersTotal = 0;
    function processNext(i) {
        if (i >= rowEls.length) {
            $btn.prop('disabled', false);
            let summary = `${successCount} ${langData['bulk_pull_result_success'] || 'created'}, ${failCount} ${langData['bulk_pull_result_failed'] || 'failed'}`;
            const notes = [];
            if (remappedTotal > 0) notes.push(`${remappedTotal} ${langData['sync_remapped_employees'] || 'employee(s) newly matched via auto-sync'}`);
            if (placeholdersTotal > 0) notes.push(`${placeholdersTotal} ${langData['sync_placeholders_created'] || 'placeholder employee(s) created from sync data -- please complete their profiles'}`);
            if (notes.length > 0) {
                summary += ` (${notes.join(', ')})`;
            }
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
                    remappedTotal += Number(res.sync_summary?.remapped_count) || 0;
                    placeholdersTotal += Number(res.sync_summary?.placeholders_created) || 0;
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
                const remapped = Number(res.sync_summary?.remapped_count) || 0;
                const placeholders = Number(res.sync_summary?.placeholders_created) || 0;
                const notes = [];
                if (remapped > 0) notes.push(`${remapped} ${langData['sync_remapped_employees'] || 'employee(s) newly matched via auto-sync'}`);
                if (placeholders > 0) notes.push(`${placeholders} ${langData['sync_placeholders_created'] || 'placeholder employee(s) created from sync data -- please complete their profiles'}`);
                const successMsg = notes.length > 0
                    ? `${langData['save_success'] || 'Saved successfully.'} (${notes.join(', ')})`
                    : (langData['save_success'] || 'Saved successfully.');
                showSuccess(successMsg);
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

/* ---------- Row "Cancel" action ---------- */
$(document).on('click', '.btn-cancel-run', function (e) {
    e.stopPropagation();
    $('#cancel_run_id').val($(this).data('id'));
    $('#cancel_reason').val('').removeClass('is-invalid').attr('placeholder', langData['cancel_reason_placeholder'] || 'Explain why this payroll run is being cancelled...');
    new bootstrap.Modal(document.getElementById('cancelRunModal')).show();
});
$(document).on('submit', '#cancelRunForm', function (e) {
    e.preventDefault();
    const reason = $('#cancel_reason').val().trim();
    if (!reason) {
        $('#cancel_reason').addClass('is-invalid');
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.cancel`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify({ id: $('#cancel_run_id').val(), reason: reason }),
        success: function (res) {
            if (res.status) {
                showSuccess(langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('cancelRunModal')).hide();
                refreshAfterRunMutation();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
        }
    });
});

/* ---------- Row "Delete" action (draft only) ---------- */
$(document).on('click', '.btn-delete-run', function (e) {
    e.stopPropagation();
    const id = $(this).data('id');
    const title = langData['confirm_delete_title'] || 'Confirm Delete';
    const message = langData['confirm_delete_run_message'] || 'Delete this draft payroll run? This cannot be undone.';
    showConfirm(title, message, function () {
        $.ajax({
            url: `${BASE_URL}/api/payroll-run.delete`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({ id: id }),
            success: function (res) {
                if (res.status) {
                    showSuccess(langData['delete_success'] || 'Deleted successfully.');
                    refreshAfterRunMutation();
                } else {
                    showWarning(res.message || langData['delete_failed'] || 'Failed to delete data.');
                }
            },
            error: function () {
                showWarning(langData['delete_failed'] || 'An error occurred while deleting the data.');
            }
        });
    });
});

$(document).ready(function () {
    registerStationSearchFilter();
    initPayrollRunTable();
    loadPendingSyncCount();
    if (typeof initSelect2 === 'function') {
        initSelect2('#run_cycle_id', { mode: 'ajax' });
        initSelect2('#run_purpose', { mode: 'static' });
    }
    updateComputeStatutoryVisibility();
    if (typeof initDatepicker === 'function') {
        initDatepicker('#filter_date_from');
        initDatepicker('#filter_date_to');
        initDatepicker('#run_period_start');
        initDatepicker('#run_period_end');
        initDatepicker('#run_payment_date');
    }
    updateClearFilterVisibility();
});
