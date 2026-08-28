// "Sync Holidays from Google Calendar" picker (Time & Leave > Setup & Rules > Holiday tab,
// 2026-08-28) -- self-contained, same convention as every other page's own JS in this app. Same
// architecture as public/js/employee/employee-sync.js (built earlier this session): fetch, split
// New vs. Already Exists via proper DataTables data/columns config (not raw HTML injection -- see
// that file's own "real bug found and fixed" comment on why), checkboxes drive a selected-dates
// Set, apply only what's ticked, everything logged. Unlike Employee Sync, this one calls a REAL
// API (GOOGLE_CALENDAR_API_KEY is genuinely configured) -- HolidaySyncModel::requireConnected()
// still gates it defensively, so #holidaySyncNotConnected is real dead code most of the time, not
// unreachable-by-design.
let holidaySyncSelectedDates = new Set();
let holidaySyncLastNewRows = [];
let holidaySyncLastExistingRows = [];

function hsEscapeHtml(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}

function hsHolidayName(row) {
    return (currentLang === 'en' ? row.name_en : row.name_th) || row.name_th || row.name_en || '';
}

function hsNotesCellHtml(row) {
    if (row.name_translated === false && currentLang === 'en') {
        return `<span class="text-muted small" title="${langData['holiday_sync_untranslated_note'] || ''}"><i class="fa-solid fa-circle-info me-1"></i>${langData['holiday_sync_untranslated_note'] || ''}</span>`;
    }
    return '';
}

function hsCheckboxCellHtml(date) {
    return `<input type="checkbox" class="holiday-sync-row-check" data-date="${date}"${holidaySyncSelectedDates.has(date) ? ' checked' : ''}>`;
}

function hsUpdateBadgeHtml(row) {
    return row.has_update
        ? `<span class="badge bg-warning-subtle text-warning"><i class="fa-solid fa-rotate me-1"></i>${langData['employee_sync_update_available'] || 'Update available'}</span>`
        : `<span class="badge bg-success-subtle text-success">${langData['employee_sync_up_to_date'] || 'Up to date'}</span>`;
}

// 2026-08-28, explicit request: "ปรับข้อมูลตาราง ตรง Sync ให้ดูสวยขึ้น" (make the Sync tables look
// nicer). Date+Holiday Name merged into one "calendar date chip" cell (brand orange #FF9900 per
// this app's own UI convention) instead of two flat text columns -- reads like an actual calendar
// entry rather than a spreadsheet row.
function hsRenderHolidayCell(row) {
    const d = new Date(row.holiday_date + 'T00:00:00');
    const locale = currentLang === 'th' ? 'th-TH' : 'en-US';
    const monthAbbr = new Intl.DateTimeFormat(locale, { month: 'short' }).format(d);
    const weekday = new Intl.DateTimeFormat(locale, { weekday: 'short' }).format(d);
    const name = hsHolidayName(row);
    return `
        <div class="d-flex align-items-center gap-2 py-1">
            <div class="text-center rounded flex-shrink-0" style="width:42px;background:#fff3e0;border:1px solid #ffe0b2;">
                <div class="text-uppercase fw-bold" style="color:#FF9900;font-size:.62rem;line-height:1.3;">${hsEscapeHtml(monthAbbr)}</div>
                <div class="fw-bold" style="font-size:1rem;line-height:1.2;">${d.getDate()}</div>
            </div>
            <div class="lh-sm">
                <div class="fw-semibold">${hsEscapeHtml(name)}</div>
                <div class="text-muted small">${hsEscapeHtml(weekday)}</div>
            </div>
        </div>
    `;
}

function hsResetModal() {
    holidaySyncSelectedDates = new Set();
    holidaySyncLastNewRows = [];
    holidaySyncLastExistingRows = [];
    $('#holidaySyncResultArea').addClass('d-none');
    $('#holidaySyncEmptyHint').removeClass('d-none');
    $('#holidaySyncNotConnected').addClass('d-none');
    $('#holidaySyncFilterRow').removeClass('d-none');
    if ($.fn.DataTable.isDataTable('#tb_holiday_sync_new')) { $('#tb_holiday_sync_new').DataTable().clear().draw(); }
    if ($.fn.DataTable.isDataTable('#tb_holiday_sync_existing')) { $('#tb_holiday_sync_existing').DataTable().clear().draw(); }
    $('#holidaySyncNewCount, #holidaySyncExistingCount, #holidaySyncSelectedCount').text('0');
    $('#holidaySyncSelectedCountLabel').text('');
    $('#btnApplyHolidaySync').addClass('d-none');
    $('#holidaySyncNewSelectAll, #holidaySyncExistingSelectAll').prop('checked', false);
}

function hsUpdateSelectedCount() {
    const n = holidaySyncSelectedDates.size;
    $('#holidaySyncSelectedCount').text(n);
    $('#btnApplyHolidaySync').toggleClass('d-none', n === 0);
    $('#holidaySyncSelectedCountLabel').text(n > 0 ? `${n} ${langData['employee_sync_selected_suffix'] || 'selected'}` : '');
}

function hsRenderTables() {
    $('#tb_holiday_sync_new').DataTable({
        destroy: true, responsive: true, paging: false, info: false, searching: false,
        data: holidaySyncLastNewRows,
        order: [[1, 'asc']],
        language: { emptyTable: langData['employee_sync_no_candidates'] || 'No candidates found.' },
        columns: [
            { data: 'holiday_date', orderable: false, className: 'text-center', render: d => hsCheckboxCellHtml(d) },
            { data: 'holiday_date', render: { display: (d, t, row) => hsRenderHolidayCell(row), sort: d => d, filter: d => d } },
            { data: null, orderable: false, render: (d, t, row) => hsNotesCellHtml(row) },
        ],
    });
    $('#tb_holiday_sync_existing').DataTable({
        destroy: true, responsive: true, paging: false, info: false, searching: false,
        data: holidaySyncLastExistingRows,
        order: [[1, 'asc']],
        language: { emptyTable: langData['employee_sync_no_candidates'] || 'No candidates found.' },
        columns: [
            { data: 'holiday_date', orderable: false, className: 'text-center', render: d => hsCheckboxCellHtml(d) },
            { data: 'holiday_date', render: { display: (d, t, row) => hsRenderHolidayCell(row), sort: d => d, filter: d => d } },
            { data: null, orderable: false, render: (d, t, row) => hsUpdateBadgeHtml(row) },
        ],
    });
}

function hsPopulateYearSelect() {
    const $sel = $('#holiday_sync_year');
    if ($sel.find('option').length > 0) return;
    const currentYear = new Date().getFullYear();
    // Current year plus the next 2 -- a payroll admin realistically syncs this year (catching up)
    // or plans ahead for next year/the year after, not an arbitrary date picker.
    for (let y = currentYear; y <= currentYear + 2; y++) {
        $sel.append(`<option value="${y}">${y}</option>`);
    }
}

function hsFetchCandidates() {
    const $btn = $('#btnFetchHolidaySync').prop('disabled', true);
    $.ajax({
        url: `${BASE_URL}/api/holiday-sync.candidates`,
        method: 'POST',
        data: { year: $('#holiday_sync_year').val() },
        dataType: 'json',
        success: function (res) {
            $btn.prop('disabled', false);
            if (res && res.not_connected) {
                $('#holidaySyncNotConnectedMessage').text(res.message || '');
                $('#holidaySyncNotConnected').removeClass('d-none');
                $('#holidaySyncFilterRow').addClass('d-none');
                return;
            }
            if (!res || !res.status) {
                showWarning((res && res.message) || langData['employee_sync_fetch_failed'] || 'Failed to fetch holidays.');
                return;
            }
            holidaySyncSelectedDates = new Set();
            holidaySyncLastNewRows = res.new || [];
            holidaySyncLastExistingRows = res.existing || [];
            $('#holidaySyncEmptyHint').addClass('d-none');
            $('#holidaySyncResultArea').removeClass('d-none');
            hsRenderTables();
            $('#holidaySyncNewCount').text(holidaySyncLastNewRows.length);
            $('#holidaySyncExistingCount').text(holidaySyncLastExistingRows.length);
            $('#holidaySyncNewSelectAll, #holidaySyncExistingSelectAll').prop('checked', false);
            hsUpdateSelectedCount();
        },
        error: function () {
            $btn.prop('disabled', false);
            showWarning(langData['employee_sync_fetch_failed'] || 'Failed to fetch holidays.');
        }
    });
}

$(document).on('click', '#btnOpenHolidaySync', function () {
    hsResetModal();
    hsPopulateYearSelect();
    new bootstrap.Modal(document.getElementById('holidaySyncModal')).show();
});

$(document).on('click', '#btnFetchHolidaySync', hsFetchCandidates);

$(document).on('change', '.holiday-sync-row-check', function () {
    const date = $(this).data('date');
    if ($(this).is(':checked')) {
        holidaySyncSelectedDates.add(date);
    } else {
        holidaySyncSelectedDates.delete(date);
    }
    hsUpdateSelectedCount();
});

$(document).on('change', '#holidaySyncNewSelectAll', function () {
    const checked = $(this).is(':checked');
    holidaySyncLastNewRows.forEach(row => { if (checked) holidaySyncSelectedDates.add(row.holiday_date); else holidaySyncSelectedDates.delete(row.holiday_date); });
    $('#tb_holiday_sync_new .holiday-sync-row-check').prop('checked', checked);
    hsUpdateSelectedCount();
});
$(document).on('change', '#holidaySyncExistingSelectAll', function () {
    const checked = $(this).is(':checked');
    holidaySyncLastExistingRows.forEach(row => { if (checked) holidaySyncSelectedDates.add(row.holiday_date); else holidaySyncSelectedDates.delete(row.holiday_date); });
    $('#tb_holiday_sync_existing .holiday-sync-row-check').prop('checked', checked);
    hsUpdateSelectedCount();
});

$(document).on('click', '#btnApplyHolidaySync', function () {
    const dates = Array.from(holidaySyncSelectedDates);
    if (!dates.length) return;
    showConfirm(
        langData['employee_sync_confirm_title'] || 'Sync selected records?',
        (langData['holiday_sync_confirm_message'] || 'This will insert/update {count} holiday record(s) in this system.').replace('{count}', dates.length),
        function () {
            const $btn = $('#btnApplyHolidaySync').prop('disabled', true);
            $.ajax({
                url: `${BASE_URL}/api/holiday-sync.apply`,
                method: 'POST',
                data: { year: $('#holiday_sync_year').val(), dates: dates },
                dataType: 'json',
                success: function (res) {
                    $btn.prop('disabled', false);
                    if (!res || !res.status) {
                        showWarning((res && res.message) || langData['employee_sync_apply_failed'] || 'Failed to sync selected holidays.');
                        return;
                    }
                    const tpl = langData['employee_sync_result_message'] || 'Synced {success} of {total} record(s).{errors}';
                    const errorsMsg = res.error > 0 ? ` ${res.error} ${langData['employee_sync_result_failed_suffix'] || 'failed.'}` : '';
                    showSuccess(tpl.replace('{success}', res.success).replace('{total}', res.total).replace('{errors}', errorsMsg));
                    if (typeof dtHoliday !== 'undefined' && dtHoliday) {
                        dtHoliday.ajax.reload(null, false);
                    }
                    hsFetchCandidates();
                },
                error: function () {
                    $btn.prop('disabled', false);
                    showWarning(langData['employee_sync_apply_failed'] || 'Failed to sync selected holidays.');
                }
            });
        }
    );
});

function hsSyncLogStatusBadge(status) {
    const map = { completed: 'bg-success-subtle text-success', running: 'bg-warning-subtle text-warning', failed: 'bg-danger-subtle text-danger' };
    const cls = map[status] || 'bg-light text-dark';
    const text = langData['sync_log_status_' + status] || status;
    return `<span class="badge ${cls}">${text}</span>`;
}

function hsLoadSyncLog() {
    $('#tb_holiday_sync_log tbody').html(`<tr><td colspan="6" class="text-center text-muted py-3"><i class="fa-solid fa-spinner fa-spin me-1"></i>${langData['loading'] || 'Loading...'}</td></tr>`);
    $.ajax({
        url: `${BASE_URL}/api/holiday-sync.log`,
        method: 'GET',
        dataType: 'json',
        success: function (res) {
            const rows = (res && res.status) ? (res.data || []) : [];
            if (!rows.length) {
                $('#tb_holiday_sync_log tbody').html(`<tr><td colspan="6" class="text-center text-muted py-3">${langData['employee_sync_log_empty'] || 'No sync history yet.'}</td></tr>`);
                return;
            }
            $('#tb_holiday_sync_log tbody').html(rows.map(function (r) {
                const byName = (currentLang === 'th' ? r.triggered_by_name_th : r.triggered_by_name_en) || r.triggered_by_name_th || r.triggered_by_name_en || '-';
                const dateStr = typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(r.started_at) : r.started_at;
                return `
                    <tr>
                        <td>${hsEscapeHtml(dateStr)}</td>
                        <td>${hsEscapeHtml(byName)}</td>
                        <td>${hsSyncLogStatusBadge(r.status)}</td>
                        <td class="text-end">${hsEscapeHtml(r.total_count)}</td>
                        <td class="text-end text-success">${hsEscapeHtml(r.success_count)}</td>
                        <td class="text-end ${Number(r.error_count) > 0 ? 'text-danger' : ''}">${hsEscapeHtml(r.error_count)}</td>
                    </tr>
                `;
            }).join(''));
        },
        error: function () {
            $('#tb_holiday_sync_log tbody').html(`<tr><td colspan="6" class="text-center text-danger py-3">${langData['employee_sync_fetch_failed'] || 'Failed to load.'}</td></tr>`);
        }
    });
}

$(document).on('click', '#btnOpenHolidaySyncLog', function () {
    new bootstrap.Modal(document.getElementById('holidaySyncLogModal')).show();
    hsLoadSyncLog();
});
