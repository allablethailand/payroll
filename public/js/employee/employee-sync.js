// "Sync Employee from Origami" picker (Employee List page, 2026-08-28) -- self-contained, same
// convention as every other page's own JS in this app (escapeHtml/fmtNum etc. duplicated locally
// rather than shared). Candidate data is REAL as of the same day (OrigamiEmployeeCandidateClient
// switched from a mock to a real HTTP client) -- see docs/origami-employee-sync-api-guide.md.
let syncSelectedRefIds = new Set();
let syncLastNewRows = [];
let syncLastExistingRows = [];

function esEscapeHtml(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}

function esCandidateName(row) {
    const first = currentLang === 'en' ? (row.name_en || row.name_th) : (row.name_th || row.name_en);
    const last = currentLang === 'en' ? (row.surname_en || row.surname_th) : (row.surname_th || row.surname_en);
    return [first, last].filter(Boolean).join(' ');
}

function esCandidateDepartment(row) {
    const name = currentLang === 'en' ? (row.department_name_en || row.department_name_th) : (row.department_name_th || row.department_name_en);
    return name || '-';
}

function esCandidatePosition(row) {
    const name = currentLang === 'en' ? (row.position_name_en || row.position_name_th) : (row.position_name_th || row.position_name_en);
    return name || '-';
}

function esTypeLabel(type) {
    return langData['employee_sync_type_' + type] || type || '-';
}

// 2026-08-28, explicit request: "ปรับข้อมูลตาราง ตรง Sync ให้ดูสวยขึ้น" (make the Sync tables look
// nicer). Employee No.+Name merged into one identity cell (avatar circle -- same #007aff style
// Employee List's own table already uses for this exact purpose, so a candidate row reads like a
// real record instead of a flat spreadsheet line) and Department+Position merged into one stacked
// cell (New tab only -- Existing tab dropped Position entirely to make room for its own Type
// column, since "has_update" -- not org placement -- is the decision that matters there).
//
// 2026-08-29, same-day follow-up ("ตารางที่แสดงผลอยู่ดูแน่นมาก ช่วยปรับให้สวยขึ้นหน่อยครับ" -- the table
// looks very cramped) -- the Type badge that used to be its own column (barely wide enough for its
// label in a half-modal-width panel) now renders INLINE in this same identity cell instead, next to
// the name, freeing a whole column's worth of width for Department/Position to actually be
// readable. `.es-sync-avatar` (size/shadow) moved into style.css instead of inline styles, matching
// this app's own convention of not hand-rolling one-off inline CSS for anything reused.
function esRenderEmployeeCell(row, employeeNo, type) {
    const name = esCandidateName(row) || '-';
    const letter = name.trim().charAt(0).toUpperCase() || '?';
    const typeBadge = type ? ` ${esTypeBadgeHtml(type)}` : '';
    return `
        <div class="d-flex align-items-center gap-2 py-1">
            <div class="es-sync-avatar rounded-circle d-flex align-items-center justify-content-center fw-bold text-white flex-shrink-0" style="background-color:#007aff;">${esEscapeHtml(letter)}</div>
            <div class="lh-sm">
                <div class="fw-semibold">${esEscapeHtml(name)}${typeBadge}</div>
                <div class="text-muted small">${esEscapeHtml(employeeNo || '-')}</div>
            </div>
        </div>
    `;
}
function esRenderDeptPositionCell(row) {
    const dept = esCandidateDepartment(row);
    const pos = esCandidatePosition(row);
    return `
        <div class="lh-sm">
            <div>${esEscapeHtml(dept)}</div>
            <div class="text-muted small">${esEscapeHtml(pos)}</div>
        </div>
    `;
}
function esTypeBadgeHtml(type) {
    const cls = type === 'support' ? 'bg-info-subtle text-info' : 'bg-primary-subtle text-primary';
    return `<span class="badge ${cls}">${esEscapeHtml(esTypeLabel(type))}</span>`;
}

function esResetModal() {
    syncSelectedRefIds = new Set();
    syncLastNewRows = [];
    syncLastExistingRows = [];
    $('#employeeSyncResultArea').addClass('d-none');
    $('#employeeSyncEmptyHint').removeClass('d-none');
    // 2026-08-28, explicit request: block the whole picker behind a real connection -- both start
    // hidden on every open, esLoadFilterOptions() reveals exactly one of them once it knows whether
    // Origami is actually connected (never assumes "connected" as the default while checking).
    $('#employeeSyncNotConnected').addClass('d-none');
    $('#employeeSyncFilterRow').addClass('d-none');
    if ($.fn.DataTable.isDataTable('#tb_sync_new')) { $('#tb_sync_new').DataTable().clear().draw(); }
    if ($.fn.DataTable.isDataTable('#tb_sync_existing')) { $('#tb_sync_existing').DataTable().clear().draw(); }
    $('#syncNewCount, #syncExistingCount, #syncSelectedCount').text('0');
    $('#syncSelectedCountLabel').text('');
    $('#btnApplyEmployeeSync').addClass('d-none');
    $('#syncNewSelectAll, #syncExistingSelectAll').prop('checked', false);
}

function esUpdateSelectedCount() {
    const n = syncSelectedRefIds.size;
    $('#syncSelectedCount').text(n);
    $('#btnApplyEmployeeSync').toggleClass('d-none', n === 0);
    $('#syncSelectedCountLabel').text(n > 0 ? `${n} ${langData['employee_sync_selected_suffix'] || 'selected'}` : '');
}

// 2026-08-28, real bug found and fixed: "กดเลือกทั้งหมดแล้วพนักงานในตารางหายไป" (clicking Select All
// made the employees in the table disappear). Root cause: esFetchCandidates() below used to
// initialize a real DataTable instance on #tb_sync_new/#tb_sync_existing (responsive:true) but then
// wrote rows into <tbody> via plain jQuery .html() instead of DataTables' own data API
// (.rows.add()/.clear()/.draw()) -- so DataTables' own internal data model stayed at whatever it
// was at init time (empty), completely out of sync with what was actually showing in the DOM.
// responsive:true actively redraws the table FROM that internal model on its own (e.g. on a
// resize/recalculation triggered by clicking a checkbox inside the modal) -- so the very next time
// anything caused a redraw, DataTables wiped the manually-inserted rows back out to its own
// (empty) idea of the table's contents. Fixed by driving both tables entirely through DataTables'
// own `data`/`columns` config (see renderSyncTables() below) instead of raw HTML -- its internal
// model and the DOM can never disagree this way, so redraws (from Select All, responsive
// recalculation, or anything else) always render correctly. Checkbox `checked` state is computed
// at render time straight from syncSelectedRefIds (the single source of truth for what's ticked),
// so it stays correct across any redraw for the same reason.
function esCheckboxCellHtml(refId) {
    return `<input type="checkbox" class="sync-row-check" data-ref-id="${refId}"${syncSelectedRefIds.has(refId) ? ' checked' : ''}>`;
}

function esUpdateBadgeHtml(row) {
    return row.has_update
        ? `<span class="badge bg-warning-subtle text-warning" title="${esEscapeHtml((row.changed_fields || []).join(', '))}"><i class="fa-solid fa-rotate me-1"></i>${langData['employee_sync_update_available'] || 'Update available'}</span>`
        : `<span class="badge bg-success-subtle text-success">${langData['employee_sync_up_to_date'] || 'Up to date'}</span>`;
}

function renderSyncTables() {
    $('#tb_sync_new').DataTable({
        destroy: true, responsive: true, paging: false, info: false, searching: false, ordering: false,
        data: syncLastNewRows,
        language: { emptyTable: langData['employee_sync_no_candidates'] || 'No candidates found.' },
        columns: [
            { data: 'ref_id', orderable: false, className: 'text-center', render: d => esCheckboxCellHtml(d) },
            { data: null, render: (d, t, row) => esRenderEmployeeCell(row, row.employee_no, row.type) },
            { data: null, render: (d, t, row) => esRenderDeptPositionCell(row) },
        ],
    });
    $('#tb_sync_existing').DataTable({
        destroy: true, responsive: true, paging: false, info: false, searching: false, ordering: false,
        data: syncLastExistingRows,
        language: { emptyTable: langData['employee_sync_no_candidates'] || 'No candidates found.' },
        columns: [
            { data: 'ref_id', orderable: false, className: 'text-center', render: d => esCheckboxCellHtml(d) },
            { data: null, render: (d, t, row) => esRenderEmployeeCell(row, row.existing_employee_no || row.employee_no, row.type) },
            { data: null, render: (d, t, row) => esEscapeHtml(esCandidateDepartment(row)) },
            { data: null, render: (d, t, row) => esUpdateBadgeHtml(row) },
        ],
    });
}

function esFilters() {
    return {
        department_ref_id: $('#sync_filter_department').val() || '',
        position_ref_id: $('#sync_filter_position').val() || '',
        type: $('#sync_filter_type').val() || '',
        team_ref_id: $('#sync_filter_team').val() || '',
    };
}

// 2026-08-28, explicit follow-up: filter OPTIONS come from Origami itself (real, as of the same
// day), not Payroll's own local department/position/team endpoints -- see
// api/employee-sync.filter-options. Fetched once per modal open and rendered as real <option>
// tags, then initialized as select2 'native' mode (per this app's own Select2 convention -- every
// dropdown must be initialized through initSelect2(), even one with no remote/static i18n source).
function esOptionsHtml(items, valueKey, labelFn) {
    const placeholder = `<option value="">${langData['select_option'] || 'Select...'}</option>`;
    return placeholder + items.map(item => `<option value="${esEscapeHtml(item[valueKey])}">${esEscapeHtml(labelFn(item))}</option>`).join('');
}

// 2026-08-30, real bug found and fixed (explicit report: "Select option 'Sync' modal ไม่เปลี่ยน
// ภาษา") -- esOptionsHtml() bakes both the "Select..." placeholder AND every real option's label
// (department/position names picked by currentLang at the moment this ran) into plain <option>
// tags ONCE, when the modal's filter-options fetch completes -- there was no code path that ever
// rebuilt them again. applyLanguage()'s existing `.select2-native` sweep only re-inits Select2's
// own chrome (destroys/rebuilds the overlay widget), it never touches the underlying <option>
// elements' text at all, so the options stayed frozen in whichever language was active the moment
// the modal was first opened, even after a language switch or on a later re-open in the same page
// session. Fixed by caching the last-fetched response and extracting the option-rendering into its
// own re-callable function, wired into changeLanguage() the same way dashboard.js's own
// loadDashboardSummary() already is (a `typeof x === 'function'` guard, since this file only loads
// on the Employee List page).
let esLastFilterOptionsRes = null;
function esRenderFilterOptions(res) {
    // Preserve whatever's currently selected in each dropdown across the rebuild -- same
    // capture-before/restore-after convention applyLanguage()'s own .select2-remote/.select2-static
    // sweeps already use, so switching language mid-filter doesn't silently clear the user's picks.
    const selected = {
        department: $('#sync_filter_department').val(),
        position: $('#sync_filter_position').val(),
        team: $('#sync_filter_team').val(),
        type: $('#sync_filter_type').val(),
    };
    $('#sync_filter_department').html(esOptionsHtml(res.departments || [], 'ref_id', d => (currentLang === 'en' ? (d.name_en || d.name_th) : (d.name_th || d.name_en))));
    $('#sync_filter_position').html(esOptionsHtml(res.positions || [], 'ref_id', p => (currentLang === 'en' ? (p.name_en || p.name_th) : (p.name_th || p.name_en))));
    $('#sync_filter_team').html(esOptionsHtml(res.teams || [], 'ref_id', t => t.name));
    $('#sync_filter_type').html(esOptionsHtml(res.types || [], 'value', t => (currentLang === 'en' ? t.label_en : t.label_th)));
    if (selected.department) $('#sync_filter_department').val(selected.department);
    if (selected.position) $('#sync_filter_position').val(selected.position);
    if (selected.team) $('#sync_filter_team').val(selected.team);
    if (selected.type) $('#sync_filter_type').val(selected.type);
    if (typeof initSelect2 === 'function') {
        initSelect2('#sync_filter_department, #sync_filter_position, #sync_filter_team, #sync_filter_type', { mode: 'native' });
    }
}
// Called from changeLanguage() (app.js) -- a no-op if the modal was never opened this page session
// (nothing cached yet) or the Sync modal isn't currently showing any filter row at all.
function esRefreshFilterOptionsLanguage() {
    if (esLastFilterOptionsRes) {
        esRenderFilterOptions(esLastFilterOptionsRes);
    }
}

// 2026-08-28, explicit request: "ถ้ายังเชื่อมไม่ได้ก็ควรแจ้งว่าเชื่อมไม่ได้ ไม่ใช่ Mock Data" -- checked
// fresh every time the modal opens (no caching flag) since whether Origami is connected could
// change without a page reload. A `not_connected: true` response shows the blocked-state panel
// and stops here -- filters/fetch/result area never render at all, so there is no path left in
// this file that can end up displaying mock/sample data to a real user.
function esLoadFilterOptions() {
    $.ajax({
        url: `${BASE_URL}/api/employee-sync.filter-options`,
        method: 'POST',
        dataType: 'json',
        success: function (res) {
            if (res && res.not_connected) {
                $('#employeeSyncNotConnectedMessage').text(res.message || langData['employee_sync_not_connected_message'] || 'The connection to Origami has not been configured yet. Please contact your system administrator.');
                $('#employeeSyncNotConnected').removeClass('d-none');
                $('#employeeSyncFilterRow').addClass('d-none');
                return;
            }
            if (!res || !res.status) {
                showWarning((res && res.message) || langData['employee_sync_fetch_failed'] || 'Failed to load filter options.');
                return;
            }
            $('#employeeSyncNotConnected').addClass('d-none');
            $('#employeeSyncFilterRow').removeClass('d-none');
            esLastFilterOptionsRes = res;
            esRenderFilterOptions(res);
        },
        error: function () {
            showWarning(langData['employee_sync_fetch_failed'] || 'Failed to load filter options.');
        }
    });
}

function esFetchCandidates() {
    const $btn = $('#btnFetchSyncCandidates').prop('disabled', true);
    $.ajax({
        url: `${BASE_URL}/api/employee-sync.candidates`,
        method: 'POST',
        data: esFilters(),
        dataType: 'json',
        success: function (res) {
            $btn.prop('disabled', false);
            // Defensive -- the filter row (and this Fetch button) only ever renders once
            // esLoadFilterOptions() already confirmed a real connection, but re-check here too in
            // case that state changed mid-session.
            if (res && res.not_connected) {
                $('#employeeSyncNotConnectedMessage').text(res.message || langData['employee_sync_not_connected_message'] || 'The connection to Origami has not been configured yet. Please contact your system administrator.');
                $('#employeeSyncNotConnected').removeClass('d-none');
                $('#employeeSyncFilterRow').addClass('d-none');
                return;
            }
            if (!res || !res.status) {
                showWarning((res && res.message) || langData['employee_sync_fetch_failed'] || 'Failed to fetch candidates.');
                return;
            }
            syncSelectedRefIds = new Set();
            syncLastNewRows = res.new || [];
            syncLastExistingRows = res.existing || [];
            $('#employeeSyncEmptyHint').addClass('d-none');
            $('#employeeSyncResultArea').removeClass('d-none');

            renderSyncTables();

            $('#syncNewCount').text(syncLastNewRows.length);
            $('#syncExistingCount').text(syncLastExistingRows.length);
            $('#syncNewSelectAll, #syncExistingSelectAll').prop('checked', false);
            esUpdateSelectedCount();
        },
        error: function () {
            $btn.prop('disabled', false);
            showWarning(langData['employee_sync_fetch_failed'] || 'Failed to fetch candidates.');
        }
    });
}

$(document).on('click', '#btnOpenEmployeeSync', function () {
    esResetModal();
    esLoadFilterOptions();
    new bootstrap.Modal(document.getElementById('employeeSyncModal')).show();
});

$(document).on('click', '#btnFetchSyncCandidates', esFetchCandidates);

$(document).on('change', '.sync-row-check', function () {
    const refId = parseInt($(this).data('ref-id'), 10);
    if ($(this).is(':checked')) {
        syncSelectedRefIds.add(refId);
    } else {
        syncSelectedRefIds.delete(refId);
    }
    esUpdateSelectedCount();
});

// Updates syncSelectedRefIds directly (the single source of truth checkbox render() reads from,
// see renderSyncTables() above) rather than only toggling the currently-rendered DOM checkboxes --
// that way the correct checked state survives even if something (e.g. the Responsive extension)
// redraws the table afterward.
$(document).on('change', '#syncNewSelectAll', function () {
    const checked = $(this).is(':checked');
    syncLastNewRows.forEach(row => { if (checked) syncSelectedRefIds.add(row.ref_id); else syncSelectedRefIds.delete(row.ref_id); });
    $('#tb_sync_new .sync-row-check').prop('checked', checked);
    esUpdateSelectedCount();
});
$(document).on('change', '#syncExistingSelectAll', function () {
    const checked = $(this).is(':checked');
    syncLastExistingRows.forEach(row => { if (checked) syncSelectedRefIds.add(row.ref_id); else syncSelectedRefIds.delete(row.ref_id); });
    $('#tb_sync_existing .sync-row-check').prop('checked', checked);
    esUpdateSelectedCount();
});

$(document).on('click', '#btnApplyEmployeeSync', function () {
    const refIds = Array.from(syncSelectedRefIds);
    if (!refIds.length) return;
    showConfirm(
        langData['employee_sync_confirm_title'] || 'Sync selected records?',
        (langData['employee_sync_confirm_message'] || 'This will insert/update {count} employee record(s) in this system.').replace('{count}', refIds.length),
        function () {
            const $btn = $('#btnApplyEmployeeSync').prop('disabled', true);
            $.ajax({
                url: `${BASE_URL}/api/employee-sync.apply`,
                method: 'POST',
                data: Object.assign({}, esFilters(), { ref_ids: refIds }),
                dataType: 'json',
                success: function (res) {
                    $btn.prop('disabled', false);
                    if (!res || !res.status) {
                        showWarning((res && res.message) || langData['employee_sync_apply_failed'] || 'Failed to sync selected records.');
                        return;
                    }
                    const tpl = langData['employee_sync_result_message'] || 'Synced {success} of {total} record(s).{errors}';
                    const errorsMsg = res.error > 0 ? ` ${res.error} ${langData['employee_sync_result_failed_suffix'] || 'failed.'}` : '';
                    showSuccess(tpl.replace('{success}', res.success).replace('{total}', res.total).replace('{errors}', errorsMsg));
                    if (typeof tb_employee !== 'undefined' && tb_employee) {
                        tb_employee.ajax.reload(null, false);
                    }
                    esFetchCandidates();
                },
                error: function () {
                    $btn.prop('disabled', false);
                    showWarning(langData['employee_sync_apply_failed'] || 'Failed to sync selected records.');
                }
            });
        }
    );
});

function esSyncLogStatusBadge(status) {
    const map = { completed: 'bg-success-subtle text-success', running: 'bg-warning-subtle text-warning', failed: 'bg-danger-subtle text-danger' };
    const cls = map[status] || 'bg-light text-dark';
    const text = langData['sync_log_status_' + status] || status;
    return `<span class="badge ${cls}">${text}</span>`;
}

// 2026-08-29, explicit request: "Employee Sync Log ปรับจากตารางให้เป็น Card และดูได้ว่า Failed จาก
// อะไร" (change from a table to Cards, and make the failure reason visible). The old table only
// ever showed Total/Success/Error as bare NUMBERS -- EmployeeSyncModel::log() already
// json_decode()s sync_batches.error_detail server-side into a real array of
// {employee_id|ref_id, message} entries, this view just never rendered any of it. Each error
// entry's identifier is whichever one that particular sync path actually recorded (resyncMany()
// stores employee_id, apply()'s bulk picker stores ref_id) -- esErrorEntryLabel() below shows
// whichever is present rather than assuming one shape for both.
function esErrorEntryLabel(entry) {
    if (entry && entry.employee_id) {
        return (langData['employee_sync_log_error_employee_prefix'] || 'Employee #{id}').replace('{id}', entry.employee_id);
    }
    if (entry && entry.ref_id) {
        return (langData['employee_sync_log_error_ref_prefix'] || 'Origami ref #{id}').replace('{id}', entry.ref_id);
    }
    return langData['employee_sync_log_error_unknown'] || 'Unknown record';
}
function esRenderSyncLogCard(r) {
    const byName = (currentLang === 'th' ? r.triggered_by_name_th : r.triggered_by_name_en) || r.triggered_by_name_th || r.triggered_by_name_en || '-';
    const dateStr = typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(r.started_at) : r.started_at;
    const errors = Array.isArray(r.error_detail) ? r.error_detail : [];
    const errorListHtml = errors.length ? `
        <div class="sync-log-card-errors">
            <div class="sync-log-card-errors-title"><i class="fa-solid fa-triangle-exclamation me-1"></i>${langData['employee_sync_log_errors_label'] || 'Failure reason(s)'}</div>
            <ul class="sync-log-card-error-list">
                ${errors.map(e => `<li><span class="fw-semibold">${esEscapeHtml(esErrorEntryLabel(e))}:</span> ${esEscapeHtml(e && e.message || '-')}</li>`).join('')}
            </ul>
        </div>
    ` : '';
    return `
        <div class="sync-log-card">
            <div class="sync-log-card-top">
                <div class="sync-log-card-when">
                    <i class="fa-regular fa-calendar me-1 text-muted"></i>${esEscapeHtml(dateStr)}
                    <span class="text-muted mx-1">&middot;</span>
                    <i class="fa-regular fa-user me-1 text-muted"></i>${esEscapeHtml(byName)}
                </div>
                ${esSyncLogStatusBadge(r.status)}
            </div>
            <div class="sync-log-card-stats">
                <div class="sync-log-stat"><span class="sync-log-stat-value">${esEscapeHtml(r.total_count)}</span><span class="sync-log-stat-label">${langData['employee_sync_log_col_total'] || 'Total'}</span></div>
                <div class="sync-log-stat sync-log-stat-success"><span class="sync-log-stat-value">${esEscapeHtml(r.success_count)}</span><span class="sync-log-stat-label">${langData['employee_sync_log_col_success'] || 'Success'}</span></div>
                <div class="sync-log-stat ${Number(r.error_count) > 0 ? 'sync-log-stat-error' : ''}"><span class="sync-log-stat-value">${esEscapeHtml(r.error_count)}</span><span class="sync-log-stat-label">${langData['employee_sync_log_col_error'] || 'Error'}</span></div>
            </div>
            ${errorListHtml}
        </div>
    `;
}
function esLoadSyncLog() {
    $('#syncLogCards').html(`<div class="text-center text-muted py-4"><i class="fa-solid fa-spinner fa-spin me-1"></i>${langData['loading'] || 'Loading...'}</div>`);
    $.ajax({
        url: `${BASE_URL}/api/employee-sync.log`,
        method: 'GET',
        dataType: 'json',
        success: function (res) {
            const rows = (res && res.status) ? (res.data || []) : [];
            if (!rows.length) {
                $('#syncLogCards').html(`<div class="text-center text-muted py-4">${langData['employee_sync_log_empty'] || 'No sync history yet.'}</div>`);
                return;
            }
            $('#syncLogCards').html(rows.map(esRenderSyncLogCard).join(''));
        },
        error: function () {
            $('#syncLogCards').html(`<div class="text-center text-danger py-4">${langData['employee_sync_fetch_failed'] || 'Failed to load.'}</div>`);
        }
    });
}

$(document).on('click', '#btnOpenEmployeeSyncLog', function () {
    new bootstrap.Modal(document.getElementById('employeeSyncLogModal')).show();
    esLoadSyncLog();
});

