let deleteContextMe = null;

function toIsoDateMe(displayVal) {
    if (!displayVal) return '';
    const parts = String(displayVal).split('/');
    if (parts.length !== 3) return displayVal;
    const [dd, mm, yyyy] = parts;
    return `${yyyy}-${mm.padStart(2, '0')}-${dd.padStart(2, '0')}`;
}
function toDisplayDateMe(isoVal) {
    if (!isoVal) return '';
    const parts = String(isoVal).substring(0, 10).split('-');
    if (parts.length !== 3) return isoVal;
    const [yyyy, mm, dd] = parts;
    return `${dd}/${mm}/${yyyy}`;
}
function escapeHtmlMe(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}
function employeeNameMe(row) {
    return currentLang === 'th' ? row.employee_name_th : row.employee_name_en;
}
function employeeCellMe(row) {
    return `${escapeHtmlMe(row.employee_no)} - ${escapeHtmlMe(employeeNameMe(row))}`;
}
function badgeMe(map, value) {
    const m = map[value] || { key: value, cls: 'bg-light text-dark' };
    return `<span class="badge ${m.cls}">${escapeHtmlMe(langData[m.key] || value)}</span>`;
}
function attendanceStatusBadge(status) {
    return badgeMe({
        present: { key: 'status_present', cls: 'bg-success-subtle text-success' },
        absent: { key: 'status_absent', cls: 'bg-danger-subtle text-danger' },
        leave: { key: 'status_leave', cls: 'bg-warning-subtle text-warning' },
        holiday: { key: 'holiday', cls: 'bg-info-subtle text-info' },
    }, status);
}
function leaveStatusBadge(status) {
    return badgeMe({
        pending: { key: 'status_pending', cls: 'bg-warning-subtle text-warning' },
        approved: { key: 'status_approved', cls: 'bg-success-subtle text-success' },
        rejected: { key: 'status_rejected', cls: 'bg-danger-subtle text-danger' },
        cancelled: { key: 'cancelled', cls: 'bg-secondary-subtle text-secondary' },
    }, status);
}
function overtimeStatusBadge(status) {
    return badgeMe({
        pending: { key: 'status_pending', cls: 'bg-warning-subtle text-warning' },
        approved: { key: 'status_approved', cls: 'bg-success-subtle text-success' },
        rejected: { key: 'status_rejected', cls: 'bg-danger-subtle text-danger' },
    }, status);
}
function sourceBadgeMe(source) {
    return badgeMe({
        manual: { key: 'source_manual', cls: 'bg-light text-dark' },
        sync: { key: 'source_sync', cls: 'bg-primary-subtle text-primary' },
        import: { key: 'source_import', cls: 'bg-info-subtle text-info' },
    }, source);
}
function actionBtnsMe(editFn, delFn) {
    return `
    <div class="btn-group border rounded-3 bg-white">
        <button class="btn btn-link text-warning" onclick="${editFn}"><i class="fa-solid fa-pen-to-square"></i></button>
        <button class="btn btn-link py-1 text-danger border-start" onclick="${delFn}"><i class="fa-solid fa-trash-can"></i></button>
    </div>`;
}
function addButtonInitCompleteMe(btnClass, iconClass, labelKey, labelFallback, onClickFnName) {
    return function () {
        const $wrapper = $(this.api().table().container());
        const $searchDiv = $wrapper.find('.dt-search');
        if ($searchDiv.find('.' + btnClass).length === 0) {
            $searchDiv.append(`<button type="button" class="btn btn-primary ms-1 ${btnClass}" onclick="${onClickFnName}"><i class="${iconClass} me-1"></i><span>${langData[labelKey] || labelFallback}</span></button>`);
        }
    };
}
function askDeleteMe(type, id, name) {
    deleteContextMe = { type, id, name };
    $('#manualEntryDeleteTargetName').text(name);
    new bootstrap.Modal(document.getElementById('manualEntryDeleteModal')).show();
}
function ajaxDeleteMe(url, table, type) {
    $.ajax({
        url: `${BASE_URL}${url}`, method: 'POST', data: { id: deleteContextMe.id }, dataType: 'json',
        success: function (res) {
            bootstrap.Modal.getInstance(document.getElementById('manualEntryDeleteModal')).hide();
            if (res.status) {
                showSuccess(res.message || langData['delete_success'] || 'Deleted successfully.');
                table.ajax.reload(null, false);
                // 2026-08-30, Phase 5 follow-up: keep the Import batch-detail modal's own table (if
                // open on this same entity type) in sync too -- see that section's own comment.
                if (typeof refreshImportBatchDetailIfOpen === 'function') { refreshImportBatchDetailIfOpen(type); }
            } else { showWarning(res.message || langData['delete_failed'] || 'Failed to delete.'); }
        },
        error: function () { bootstrap.Modal.getInstance(document.getElementById('manualEntryDeleteModal')).hide(); showWarning(langData['delete_failed'] || 'Failed to delete.'); }
    });
}
function confirmManualEntryDelete() {
    if (!deleteContextMe) return;
    const { type } = deleteContextMe;
    if (type === 'attendance') { ajaxDeleteMe('/api/manual-attendance.delete', dtAttendance, 'attendance'); }
    else if (type === 'leave') { ajaxDeleteMe('/api/manual-leave.delete', dtLeave, 'leave'); }
    else if (type === 'overtime') { ajaxDeleteMe('/api/manual-overtime.delete', dtOvertime, 'overtime'); }
    deleteContextMe = null;
}

/* ==================== ATTENDANCE ==================== */
let dtAttendance;
function renderAttendance() {
    if ($.fn.DataTable.isDataTable('#tb_attendance')) { dtAttendance.ajax.reload(null, false); return; }
    dtAttendance = $('#tb_attendance').DataTable({
        responsive: true,
        ajax: {
            url: `${BASE_URL}/api/manual-attendance.list`, dataSrc: 'data',
            data: function (d) {
                d.employee_id = $('#filter_att_employee').val() || '';
                d.date_from = toIsoDateMe($('#filter_att_date_from').val());
                d.date_to = toIsoDateMe($('#filter_att_date_to').val());
            }
        },
        columns: [
            { data: null, render: (d, t, row) => employeeCellMe(row) },
            { data: null, render: (d, t, row) => toDisplayDateMe(row.work_date) },
            { data: null, render: (d, t, row) => escapeHtmlMe((currentLang === 'th' ? row.shift_name_th : row.shift_name_en) || '-') },
            { data: null, render: (d, t, row) => row.clock_in ? String(row.clock_in).substring(11, 16) : '-' },
            { data: null, render: (d, t, row) => row.clock_out ? String(row.clock_out).substring(11, 16) : '-' },
            { data: null, className: 'text-end', render: (d, t, row) => row.actual_work_minutes ? (row.actual_work_minutes / 60).toFixed(1) : '-' },
            { data: 'status', className: 'text-center', render: (d) => attendanceStatusBadge(d) },
            { data: 'data_source', className: 'text-center', render: (d) => sourceBadgeMe(d) },
            // 2026-08-28: className:'all' keeps this last actions column from collapsing into the
            // Responsive expand row.
            { data: null, orderable: false, className: 'text-end all', render: (d, t, row) => actionBtnsMe(`openAttendanceModal(${row.id})`, `askDeleteMe('attendance', ${row.id}, '${escapeHtmlMe(employeeNameMe(row))} - ${escapeHtmlMe(toDisplayDateMe(row.work_date))}')`) }
        ],
        ordering: false, lengthChange: false, pageLength: 10,
        language: { ...getTableLang(), emptyTable: langData['no_attendance_yet'] || 'No attendance records have been added yet.' },
        initComplete: function () {
            addButtonInitCompleteMe('btn-add-attendance', 'fa-solid fa-plus', 'add_attendance', 'Attendance', 'openAttendanceModal()').call(this);
            // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
            // rollout, client mode. Excludes the actions column (8).
            initExcelColumnFilters(this.api(), {
                mode: 'client',
                columns: [
                    { index: 0, key: 'employee' },
                    { index: 1, key: 'work_date' },
                    { index: 2, key: 'shift' },
                    { index: 3, key: 'clock_in' },
                    { index: 4, key: 'clock_out' },
                    { index: 5, key: 'actual_hours' },
                    { index: 6, key: 'status' },
                    { index: 7, key: 'data_source' },
                ]
            });
        }
    });
}
function openAttendanceModal(id) {
    initSelect2('#attendanceEmployee', { mode: 'ajax' });
    initSelect2('#attendanceShift', { mode: 'ajax', allowClear: true });
    initSelect2('#attendanceStatus', { mode: 'static' });
    if (id) {
        $.ajax({
            url: `${BASE_URL}/api/manual-attendance.get`, method: 'GET', data: { id }, dataType: 'json',
            success: function (res) {
                if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
                const a = res.data;
                $('#attendanceId').val(a.id);
                $('#attendanceEmployee').empty().append(new Option(`${a.employee_no} - ${employeeNameMe(a)}`, a.employee_id, true, true)).trigger('change.select2');
                $('#attendanceWorkDate').val(toDisplayDateMe(a.work_date));
                if (a.shift_id) { $('#attendanceShift').empty().append(new Option((currentLang === 'th' ? a.shift_name_th : a.shift_name_en) || '', a.shift_id, true, true)).trigger('change.select2'); }
                else { $('#attendanceShift').empty().trigger('change.select2'); }
                $('#attendanceStatus').val(a.status).trigger('change.select2');
                $('#attendanceClockIn').val(a.clock_in ? String(a.clock_in).substring(11, 16) : '');
                $('#attendanceClockOut').val(a.clock_out ? String(a.clock_out).substring(11, 16) : '');
                $('#attendanceLateMinutes').val(a.late_minutes || 0);
                $('#attendanceEarlyMinutes').val(a.early_leave_minutes || 0);
                new bootstrap.Modal(document.getElementById('attendanceModal')).show();
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
        });
        return;
    }
    $('#attendanceId').val('');
    $('#attendanceEmployee').empty().trigger('change.select2');
    $('#attendanceShift').empty().trigger('change.select2');
    $('#attendanceWorkDate').val('');
    $('#attendanceStatus').val('present').trigger('change.select2');
    $('#attendanceClockIn').val('');
    $('#attendanceClockOut').val('');
    $('#attendanceLateMinutes').val(0);
    $('#attendanceEarlyMinutes').val(0);
    new bootstrap.Modal(document.getElementById('attendanceModal')).show();
}
function saveAttendance() {
    const employeeId = $('#attendanceEmployee').val();
    const workDate = toIsoDateMe($('#attendanceWorkDate').val());
    if (!employeeId || !workDate) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const clockInTime = $('#attendanceClockIn').val();
    const clockOutTime = $('#attendanceClockOut').val();
    const payload = {
        id: $('#attendanceId').val() || null,
        employee_id: parseInt(employeeId),
        work_date: workDate,
        shift_id: $('#attendanceShift').val() || null,
        clock_in: clockInTime ? `${workDate} ${clockInTime}:00` : null,
        clock_out: clockOutTime ? `${workDate} ${clockOutTime}:00` : null,
        status: $('#attendanceStatus').val() || 'present',
        late_minutes: parseInt($('#attendanceLateMinutes').val() || 0),
        early_leave_minutes: parseInt($('#attendanceEarlyMinutes').val() || 0),
    };
    $.ajax({
        url: `${BASE_URL}/api/manual-attendance.save`, method: 'POST', contentType: 'application/json', data: JSON.stringify(payload), dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('attendanceModal')).hide();
                dtAttendance.ajax.reload(null, false);
                refreshImportBatchDetailIfOpen('attendance');
            } else { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
}

/* ==================== LEAVE ==================== */
let dtLeave;
function renderLeave() {
    if ($.fn.DataTable.isDataTable('#tb_leave')) { dtLeave.ajax.reload(null, false); return; }
    dtLeave = $('#tb_leave').DataTable({
        responsive: true,
        ajax: {
            url: `${BASE_URL}/api/manual-leave.list`, dataSrc: 'data',
            data: function (d) {
                d.employee_id = $('#filter_leave_employee').val() || '';
                d.date_from = toIsoDateMe($('#filter_leave_date_from').val());
                d.date_to = toIsoDateMe($('#filter_leave_date_to').val());
            }
        },
        columns: [
            { data: null, render: (d, t, row) => employeeCellMe(row) },
            { data: null, render: (d, t, row) => escapeHtmlMe(currentLang === 'th' ? row.leave_type_name_th : row.leave_type_name_en) },
            { data: null, render: (d, t, row) => toDisplayDateMe(row.start_date) },
            { data: null, render: (d, t, row) => toDisplayDateMe(row.end_date) },
            { data: 'total_days', className: 'text-end' },
            { data: 'status', className: 'text-center', render: (d) => leaveStatusBadge(d) },
            { data: 'data_source', className: 'text-center', render: (d) => sourceBadgeMe(d) },
            // 2026-08-28: className:'all' keeps this last actions column from collapsing into the
            // Responsive expand row.
            { data: null, orderable: false, className: 'text-end all', render: (d, t, row) => actionBtnsMe(`openLeaveModal(${row.id})`, `askDeleteMe('leave', ${row.id}, '${escapeHtmlMe(employeeNameMe(row))} - ${escapeHtmlMe(toDisplayDateMe(row.start_date))}')`) }
        ],
        ordering: false, lengthChange: false, pageLength: 10,
        language: { ...getTableLang(), emptyTable: langData['no_leave_yet'] || 'No leave records have been added yet.' },
        initComplete: function () {
            addButtonInitCompleteMe('btn-add-leave', 'fa-solid fa-plus', 'add_leave', 'Leave', 'openLeaveModal()').call(this);
            // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
            // rollout, client mode. Excludes the actions column (7).
            initExcelColumnFilters(this.api(), {
                mode: 'client',
                columns: [
                    { index: 0, key: 'employee' },
                    { index: 1, key: 'leave_type' },
                    { index: 2, key: 'start_date' },
                    { index: 3, key: 'end_date' },
                    { index: 4, key: 'total_days' },
                    { index: 5, key: 'status' },
                    { index: 6, key: 'data_source' },
                ]
            });
        }
    });
}
function openLeaveModal(id) {
    initSelect2('#leaveEmployee', { mode: 'ajax' });
    initSelect2('#leaveType', { mode: 'ajax' });
    initSelect2('#leaveStatus', { mode: 'static' });
    if (id) {
        $.ajax({
            url: `${BASE_URL}/api/manual-leave.get`, method: 'GET', data: { id }, dataType: 'json',
            success: function (res) {
                if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
                const l = res.data;
                $('#leaveId').val(l.id);
                $('#leaveEmployee').empty().append(new Option(`${l.employee_no} - ${employeeNameMe(l)}`, l.employee_id, true, true)).trigger('change.select2');
                $('#leaveType').empty().append(new Option(currentLang === 'th' ? l.leave_type_name_th : l.leave_type_name_en, l.leave_type_id, true, true)).trigger('change.select2');
                $('#leaveStartDate').val(toDisplayDateMe(l.start_date));
                $('#leaveEndDate').val(toDisplayDateMe(l.end_date));
                $('#leaveTotalDays').val(l.total_days);
                $('#leaveStatus').val(l.status).trigger('change.select2');
                $('#leaveReason').val(l.reason || '');
                new bootstrap.Modal(document.getElementById('leaveModal')).show();
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
        });
        return;
    }
    $('#leaveId').val('');
    $('#leaveEmployee').empty().trigger('change.select2');
    $('#leaveType').empty().trigger('change.select2');
    $('#leaveStartDate').val('');
    $('#leaveEndDate').val('');
    $('#leaveTotalDays').val('');
    $('#leaveStatus').val('approved').trigger('change.select2');
    $('#leaveReason').val('');
    new bootstrap.Modal(document.getElementById('leaveModal')).show();
}
function saveLeave() {
    const employeeId = $('#leaveEmployee').val();
    const leaveTypeId = $('#leaveType').val();
    const startDate = toIsoDateMe($('#leaveStartDate').val());
    const endDate = toIsoDateMe($('#leaveEndDate').val());
    const totalDays = parseFloat($('#leaveTotalDays').val());
    if (!employeeId || !leaveTypeId || !startDate || !endDate || !totalDays || totalDays <= 0) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const payload = {
        id: $('#leaveId').val() || null,
        employee_id: parseInt(employeeId),
        leave_type_id: parseInt(leaveTypeId),
        start_date: startDate,
        end_date: endDate,
        total_days: totalDays,
        reason: $('#leaveReason').val().trim(),
        status: $('#leaveStatus').val() || 'approved',
    };
    $.ajax({
        url: `${BASE_URL}/api/manual-leave.save`, method: 'POST', contentType: 'application/json', data: JSON.stringify(payload), dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('leaveModal')).hide();
                dtLeave.ajax.reload(null, false);
                refreshImportBatchDetailIfOpen('leave');
            } else { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
}

/* ==================== OVERTIME ==================== */
let dtOvertime;
function renderOvertime() {
    if ($.fn.DataTable.isDataTable('#tb_overtime')) { dtOvertime.ajax.reload(null, false); return; }
    dtOvertime = $('#tb_overtime').DataTable({
        responsive: true,
        ajax: {
            url: `${BASE_URL}/api/manual-overtime.list`, dataSrc: 'data',
            data: function (d) {
                d.employee_id = $('#filter_ot_employee').val() || '';
                d.date_from = toIsoDateMe($('#filter_ot_date_from').val());
                d.date_to = toIsoDateMe($('#filter_ot_date_to').val());
            }
        },
        columns: [
            { data: null, render: (d, t, row) => employeeCellMe(row) },
            { data: null, render: (d, t, row) => escapeHtmlMe(currentLang === 'th' ? row.ot_name_th : row.ot_name_en) },
            { data: null, render: (d, t, row) => toDisplayDateMe(row.ot_date) },
            { data: 'hours', className: 'text-end' },
            { data: null, className: 'text-end', render: (d, t, row) => row.amount !== null ? Number(row.amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '-' },
            { data: 'status', className: 'text-center', render: (d) => overtimeStatusBadge(d) },
            { data: 'data_source', className: 'text-center', render: (d) => sourceBadgeMe(d) },
            // 2026-08-28: className:'all' keeps this last actions column from collapsing into the
            // Responsive expand row.
            { data: null, orderable: false, className: 'text-end all', render: (d, t, row) => actionBtnsMe(`openOvertimeModal(${row.id})`, `askDeleteMe('overtime', ${row.id}, '${escapeHtmlMe(employeeNameMe(row))} - ${escapeHtmlMe(toDisplayDateMe(row.ot_date))}')`) }
        ],
        ordering: false, lengthChange: false, pageLength: 10,
        language: { ...getTableLang(), emptyTable: langData['no_overtime_yet'] || 'No overtime records have been added yet.' },
        initComplete: function () {
            addButtonInitCompleteMe('btn-add-overtime', 'fa-solid fa-plus', 'add_overtime', 'Overtime', 'openOvertimeModal()').call(this);
            // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
            // rollout, client mode. Excludes the actions column (7).
            initExcelColumnFilters(this.api(), {
                mode: 'client',
                columns: [
                    { index: 0, key: 'employee' },
                    { index: 1, key: 'ot_name' },
                    { index: 2, key: 'ot_date' },
                    { index: 3, key: 'hours' },
                    { index: 4, key: 'amount' },
                    { index: 5, key: 'status' },
                    { index: 6, key: 'data_source' },
                ]
            });
        }
    });
}
function openOvertimeModal(id) {
    initSelect2('#overtimeEmployee', { mode: 'ajax' });
    initSelect2('#overtimeRate', { mode: 'ajax' });
    initSelect2('#overtimeStatus', { mode: 'static' });
    if (id) {
        $.ajax({
            url: `${BASE_URL}/api/manual-overtime.get`, method: 'GET', data: { id }, dataType: 'json',
            success: function (res) {
                if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
                const o = res.data;
                $('#overtimeId').val(o.id);
                $('#overtimeEmployee').empty().append(new Option(`${o.employee_no} - ${employeeNameMe(o)}`, o.employee_id, true, true)).trigger('change.select2');
                $('#overtimeRate').empty().append(new Option(currentLang === 'th' ? o.ot_name_th : o.ot_name_en, o.ot_rate_id, true, true)).trigger('change.select2');
                $('#overtimeDate').val(toDisplayDateMe(o.ot_date));
                $('#overtimeHours').val(o.hours);
                $('#overtimeAmount').val(o.amount !== null ? o.amount : '');
                $('#overtimeStatus').val(o.status).trigger('change.select2');
                new bootstrap.Modal(document.getElementById('overtimeModal')).show();
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
        });
        return;
    }
    $('#overtimeId').val('');
    $('#overtimeEmployee').empty().trigger('change.select2');
    $('#overtimeRate').empty().trigger('change.select2');
    $('#overtimeDate').val('');
    $('#overtimeHours').val('');
    $('#overtimeAmount').val('');
    $('#overtimeStatus').val('approved').trigger('change.select2');
    new bootstrap.Modal(document.getElementById('overtimeModal')).show();
}
function saveOvertime() {
    const employeeId = $('#overtimeEmployee').val();
    const otRateId = $('#overtimeRate').val();
    const otDate = toIsoDateMe($('#overtimeDate').val());
    const hours = parseFloat($('#overtimeHours').val());
    if (!employeeId || !otRateId || !otDate || !hours || hours <= 0) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const amountVal = $('#overtimeAmount').val();
    const payload = {
        id: $('#overtimeId').val() || null,
        employee_id: parseInt(employeeId),
        ot_rate_id: parseInt(otRateId),
        ot_date: otDate,
        hours: hours,
        amount: amountVal !== '' ? parseFloat(amountVal) : null,
        status: $('#overtimeStatus').val() || 'approved',
    };
    $.ajax({
        url: `${BASE_URL}/api/manual-overtime.save`, method: 'POST', contentType: 'application/json', data: JSON.stringify(payload), dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('overtimeModal')).hide();
                dtOvertime.ajax.reload(null, false);
                refreshImportBatchDetailIfOpen('overtime');
            } else { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
}

// 2026-08-29, same-day follow-up: system-wide page-level filter audit -- these 3 tabs' filter
// fields moved from a bare row into the standard .station-filter component (see
// app/views/manual-entry/index.php's own comment) -- toggle + conditional Clear Filter visibility
// wired the same way as every other .station-filter instance in this app (e.g.
// employee/list.js's own #employeeLoginHistoryStationFilterToggle).
function meFilterToggle(filterId, toggleId) {
    $(document).on('click', toggleId, function () {
        const $filter = $(filterId).toggleClass('collapsed');
        const collapsed = $filter.hasClass('collapsed');
        $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
    });
}
meFilterToggle('#attendanceStationFilter', '#attendanceStationFilterToggle');
meFilterToggle('#leaveStationFilter', '#leaveStationFilterToggle');
meFilterToggle('#overtimeStationFilter', '#overtimeStationFilterToggle');

function updateMeClearFilterVisibility(btnId, employeeId, dateFromId, dateToId) {
    const active = !!($(employeeId).val() || $(dateFromId).val() || $(dateToId).val());
    $(btnId).toggleClass('d-none', !active);
}
$(document).on('change', '#filter_att_employee, #filter_att_date_from, #filter_att_date_to', function () {
    updateMeClearFilterVisibility('#btnAttendanceClearFilter', '#filter_att_employee', '#filter_att_date_from', '#filter_att_date_to');
    if (dtAttendance) dtAttendance.ajax.reload(null, true);
});
$(document).on('change', '#filter_leave_employee, #filter_leave_date_from, #filter_leave_date_to', function () {
    updateMeClearFilterVisibility('#btnLeaveClearFilter', '#filter_leave_employee', '#filter_leave_date_from', '#filter_leave_date_to');
    if (dtLeave) dtLeave.ajax.reload(null, true);
});
$(document).on('change', '#filter_ot_employee, #filter_ot_date_from, #filter_ot_date_to', function () {
    updateMeClearFilterVisibility('#btnOvertimeClearFilter', '#filter_ot_employee', '#filter_ot_date_from', '#filter_ot_date_to');
    if (dtOvertime) dtOvertime.ajax.reload(null, true);
});
$(document).on('click', '#btnAttendanceClearFilter', function () {
    $('#filter_att_employee').val(null).trigger('change');
    $('#filter_att_date_from, #filter_att_date_to').val('');
    updateMeClearFilterVisibility('#btnAttendanceClearFilter', '#filter_att_employee', '#filter_att_date_from', '#filter_att_date_to');
    if (dtAttendance) dtAttendance.ajax.reload(null, true);
});
$(document).on('click', '#btnLeaveClearFilter', function () {
    $('#filter_leave_employee').val(null).trigger('change');
    $('#filter_leave_date_from, #filter_leave_date_to').val('');
    updateMeClearFilterVisibility('#btnLeaveClearFilter', '#filter_leave_employee', '#filter_leave_date_from', '#filter_leave_date_to');
    if (dtLeave) dtLeave.ajax.reload(null, true);
});
$(document).on('click', '#btnOvertimeClearFilter', function () {
    $('#filter_ot_employee').val(null).trigger('change');
    $('#filter_ot_date_from, #filter_ot_date_to').val('');
    updateMeClearFilterVisibility('#btnOvertimeClearFilter', '#filter_ot_employee', '#filter_ot_date_from', '#filter_ot_date_to');
    if (dtOvertime) dtOvertime.ajax.reload(null, true);
});

/* ==================== IMPORT (2026-08-30, Phase 5, T030-T035) ==================== */
// Thin client for ManualEntryController's importTemplate()/importPreview()/importCommit()/
// importBatchList()/importBatchDetail() -- see that controller's own docblock. T035 (editing an
// imported record) deliberately reuses openAttendanceModal()/openLeaveModal()/openOvertimeModal()
// above rather than a new edit surface.
let lastImportMappedRows = null; // the SAME mapped-rows array preview() validated, echoed straight to commit() without re-uploading the file.

function importEntityLabel(type) {
    return langData[type] || type;
}

function downloadImportTemplate() {
    const type = $('#importEntityType').val();
    if (!type) return;
    window.location.href = `${BASE_URL}/api/manual-import.template?entity_type=${encodeURIComponent(type)}`;
}

function importRowStatusBadge(row) {
    if (row.status === 'error') { return `<span class="badge bg-danger-subtle text-danger">${langData['error'] || 'Error'}</span>`; }
    if (row.source_conflict) { return `<span class="badge bg-warning-subtle text-warning">${langData['conflict'] || 'Conflict'}</span>`; }
    return `<span class="badge bg-success-subtle text-success">${langData['success'] || 'OK'}</span>`;
}

function renderImportPreviewResults(res) {
    $('#importPreviewWrap').removeClass('d-none');
    const p = res.preview;
    const badges = [
        `<span class="badge bg-secondary">${langData['total'] || 'Total'}: ${p.total}</span>`,
        `<span class="badge bg-success">${langData['success'] || 'Success'}: ${p.success}</span>`,
        `<span class="badge bg-danger">${langData['error'] || 'Error'}: ${p.error}</span>`,
        `<span class="badge bg-warning text-dark">${langData['conflict'] || 'Conflict'}: ${p.conflict || 0}</span>`,
    ];
    $('#importSummaryBadges').html(badges.join(' '));

    if (res.unmapped_headers && res.unmapped_headers.length > 0) {
        $('#importUnmappedAlert').removeClass('d-none').html(
            `<i class="fa-solid fa-triangle-exclamation me-1"></i>${langData['import_unmapped_headers_warning'] || 'These file columns could not be matched to the template and were ignored:'} ` +
            res.unmapped_headers.map(h => `<code>${escapeHtmlMe(h)}</code>`).join(', ')
        );
    } else {
        $('#importUnmappedAlert').addClass('d-none').empty();
    }

    const $tbody = $('#tb_import_preview tbody').empty();
    (p.row_results || []).forEach(r => {
        let message = r.message || '';
        if (r.source_conflict) {
            message = (langData['import_source_conflict_warning'] || 'Overwrites an existing record last touched by: {source}').replace('{source}', importEntityLabel('source_' + r.previous_source) || r.previous_source);
        }
        $tbody.append(`<tr>
            <td>${r.row}</td>
            <td>${importRowStatusBadge(r)}</td>
            <td>${escapeHtmlMe(r.action || '-')}</td>
            <td>${escapeHtmlMe(message)}</td>
        </tr>`);
    });

    lastImportMappedRows = res.mapped_rows;
    $('#btnConfirmImport').prop('disabled', !p.success || p.success === 0);
}

function previewImportFile() {
    const entityType = $('#importEntityType').val();
    const fileInput = document.getElementById('importFileInput');
    if (!entityType || !fileInput.files.length) {
        showWarning(langData['import_select_file_first'] || 'Choose a data type and a file first.');
        return;
    }
    const formData = new FormData();
    formData.append('entity_type', entityType);
    formData.append('file', fileInput.files[0]);
    $.ajax({
        url: `${BASE_URL}/api/manual-import.preview`, method: 'POST', data: formData, processData: false, contentType: false, dataType: 'json',
        success: function (res) {
            if (!res.status) { showWarning(res.message || langData['import_failed'] || 'Import preview failed.'); return; }
            renderImportPreviewResults(res);
        },
        error: function () { showWarning(langData['import_failed'] || 'Import preview failed.'); }
    });
}

function confirmImportCommit() {
    if (!lastImportMappedRows || !lastImportMappedRows.length) { return; }
    const entityType = $('#importEntityType').val();
    $.ajax({
        url: `${BASE_URL}/api/manual-import.commit`, method: 'POST',
        data: { entity_type: entityType, mapped_rows: JSON.stringify(lastImportMappedRows) }, dataType: 'json',
        success: function (res) {
            if (!res.status) { showWarning(res.message || langData['import_failed'] || 'Import failed.'); return; }
            showSuccess((langData['import_commit_success'] || '{success} imported, {error} failed.').replace('{success}', res.success).replace('{error}', res.error));
            $('#importPreviewWrap').addClass('d-none');
            $('#importFileInput').val('');
            lastImportMappedRows = null;
            if (dtImportHistory) { dtImportHistory.ajax.reload(null, false); }
            if (entityType === 'attendance' && dtAttendance) { dtAttendance.ajax.reload(null, false); }
            if (entityType === 'leave' && dtLeave) { dtLeave.ajax.reload(null, false); }
            if (entityType === 'overtime' && dtOvertime) { dtOvertime.ajax.reload(null, false); }
        },
        error: function () { showWarning(langData['import_failed'] || 'Import failed.'); }
    });
}

// 2026-08-30, explicit follow-up request: "เก็บประวัติการ Download ข้อมูลออกจากระบบ และการ Import ข้อมูล
// เข้าระบบเรียบร้อยแล้วใช่ไหมครับ...เพิ่ม Tab ในการดูประวัติการ Download Upload ด้วยครับ" -- replaces the
// old import-only `tb_import_batches` table (its own "Import History" card, previously living
// inside the Import tab) with a unified table on this NEW History tab, backed by
// ManualEntryController::importActivityLog() -> ImportActivityLogModel's own UNION ALL of
// import_template_download_logs (Download) + sync_batches (Import). Server-side date-range filter
// (same as Reports' own Download History modal, public/js/payroll/detail.js's
// dtReportHistory) + client-side Excel column filters on top of whatever page is loaded.
let dtImportHistory = null;
function importEventTypeBadge(eventType) {
    return badgeMe({
        download: { key: 'download', cls: 'bg-info-subtle text-info' },
        import: { key: 'import', cls: 'bg-primary-subtle text-primary' },
    }, eventType);
}
function importHistoryStatusBadge(row) {
    if (row.event_type === 'download') { return `<span class="badge bg-success-subtle text-success">${langData['success'] || 'Success'}</span>`; }
    return badgeMe({
        running: { key: 'status_pending', cls: 'bg-warning-subtle text-warning' },
        completed: { key: 'status_approved', cls: 'bg-success-subtle text-success' },
        failed: { key: 'status_rejected', cls: 'bg-danger-subtle text-danger' },
    }, row.status);
}
function importHistoryByLabel(row) {
    return (currentLang === 'th' ? row.performed_by_name_th : row.performed_by_name_en) || row.performed_by_name_th || row.performed_by_name_en || '-';
}
function importHistoryDeviceLabel(row) {
    if (!row.device_type) return '-';
    return row.os_name ? `${row.device_type} (${row.os_name})` : row.device_type;
}
function importHistoryBrowserLabel(row) {
    if (!row.browser_name) return '-';
    return row.browser_version ? `${row.browser_name} ${row.browser_version}` : row.browser_name;
}
function updateImportHistoryClearFilterVisibility() {
    const active = !!($('#filter_ih_event_type').val() || $('#filter_ih_entity_type').val() || $('#filter_ih_date_from').val() || $('#filter_ih_date_to').val());
    $('#btnImportHistoryClearFilter').toggleClass('d-none', !active);
}
function renderImportHistory() {
    if ($.fn.DataTable.isDataTable('#tb_import_history')) { dtImportHistory.ajax.reload(null, false); return; }
    dtImportHistory = $('#tb_import_history').DataTable({
        responsive: true,
        ajax: {
            url: `${BASE_URL}/api/manual-import.activity-log`, dataSrc: 'data',
            data: function (d) {
                d.event_type = $('#filter_ih_event_type').val() || '';
                d.entity_type = $('#filter_ih_entity_type').val() || '';
                d.date_from = toIsoDateMe($('#filter_ih_date_from').val());
                d.date_to = toIsoDateMe($('#filter_ih_date_to').val());
            }
        },
        columns: [
            { data: 'performed_at', render: { display: (v) => v ? String(v).replace('T', ' ').substring(0, 16) : '-', sort: (v) => v || '', filter: (v) => v || '' } },
            { data: 'event_type', className: 'text-center', render: (d) => importEventTypeBadge(d) },
            { data: null, render: (d, t, row) => escapeHtmlMe(importEntityLabel(row.entity_type)) },
            { data: null, render: (d, t, row) => escapeHtmlMe(importHistoryByLabel(row)) },
            { data: null, render: (d, t, row) => escapeHtmlMe(importHistoryDeviceLabel(row)) },
            { data: null, render: (d, t, row) => escapeHtmlMe(importHistoryBrowserLabel(row)) },
            { data: 'ip_address', render: (v) => escapeHtmlMe(v || '-') },
            { data: null, className: 'text-center', render: (d, t, row) => importHistoryStatusBadge(row) },
            { data: null, orderable: false, className: 'text-end all', render: (d, t, row) => row.event_type === 'import'
                ? `<div class="btn-group border rounded-3 bg-white"><button class="btn btn-link text-primary" onclick="openImportBatchDetail(${row.id}, '${row.entity_type}')"><i class="fa-solid fa-eye"></i></button></div>`
                : '' },
        ],
        order: [[0, 'desc']],
        pageLength: pageLength, lengthMenu: lengthMenu,
        language: { ...getTableLang(), emptyTable: langData['no_import_batches_yet'] || 'No imports have been run yet.' },
        initComplete: function () {
            // Per this app's own DataTable convention (CLAUDE.md): every new list-table calls
            // initExcelColumnFilters(), excludes only the button-only Actions column (8).
            initExcelColumnFilters(this.api(), {
                mode: 'client',
                columns: [
                    { index: 1, key: 'event_type' },
                    { index: 2, key: 'entity_type' },
                    { index: 3, key: 'by' },
                    { index: 4, key: 'device' },
                    { index: 5, key: 'browser' },
                    { index: 6, key: 'ip_address' },
                    { index: 7, key: 'status' },
                ]
            });
        }
    });
}

// 2026-08-30 (Phase 5 follow-up, "Phase นี้มีงานค้างไหมครับ" self-check): the batch-detail modal
// below was originally a plain manually-rendered <table>, and its rows' own Edit/Delete only
// refreshed dtAttendance/dtLeave/dtOvertime, leaving the modal's own table stale after an edit --
// both real gaps against this app's own DataTable convention (CLAUDE.md: no manual-rendered list
// table, every new table needs initExcelColumnFilters()) and a real UX bug. Fixed: this is now a
// real (client-side, `data:` array, not ajax -- the data is already fetched once by
// openImportBatchDetail() below) DataTable, re-initialized fresh each time a different batch opens
// (columns differ per entity type, so the instance is destroyed and rebuilt rather than reused).
// `currentImportBatchContext` + refreshImportBatchDetailIfOpen() keep it in sync with edits/deletes
// made via the SAME openAttendanceModal()/openLeaveModal()/openOvertimeModal()/askDeleteMe() this
// file's other 3 tabs already use (T035's own reuse-don't-duplicate design, unchanged) -- see the
// save*()/ajaxDeleteMe() call sites further up in this file for where this hook is wired in.
let dtImportBatchDetail = null;
let currentImportBatchContext = null; // {batchId, entityType} while the drill-down modal is open, else null

const IMPORT_BATCH_DETAIL_COLUMNS = {
    attendance: [
        { data: null, title: langData['employee'] || 'Employee', render: (d, t, row) => employeeCellMe(row) },
        { data: null, title: langData['work_date'] || 'Work Date', render: (d, t, row) => toDisplayDateMe(row.work_date) },
        { data: 'status', title: langData['status'] || 'Status', className: 'text-center', render: (d) => attendanceStatusBadge(d) },
        { data: 'data_source', title: langData['source'] || 'Source', className: 'text-center', render: (d) => sourceBadgeMe(d) },
        { data: null, title: '', orderable: false, className: 'text-end all', render: (d, t, row) => actionBtnsMe(`openAttendanceModal(${row.id})`, `askDeleteMe('attendance', ${row.id}, '${escapeHtmlMe(employeeNameMe(row))} - ${escapeHtmlMe(toDisplayDateMe(row.work_date))}')`) },
    ],
    leave: [
        { data: null, title: langData['employee'] || 'Employee', render: (d, t, row) => employeeCellMe(row) },
        { data: null, title: langData['start_date'] || 'Start Date', render: (d, t, row) => toDisplayDateMe(row.start_date) },
        { data: null, title: langData['end_date'] || 'End Date', render: (d, t, row) => toDisplayDateMe(row.end_date) },
        { data: 'status', title: langData['status'] || 'Status', className: 'text-center', render: (d) => leaveStatusBadge(d) },
        { data: 'data_source', title: langData['source'] || 'Source', className: 'text-center', render: (d) => sourceBadgeMe(d) },
        { data: null, title: '', orderable: false, className: 'text-end all', render: (d, t, row) => actionBtnsMe(`openLeaveModal(${row.id})`, `askDeleteMe('leave', ${row.id}, '${escapeHtmlMe(employeeNameMe(row))} - ${escapeHtmlMe(toDisplayDateMe(row.start_date))}')`) },
    ],
    overtime: [
        { data: null, title: langData['employee'] || 'Employee', render: (d, t, row) => employeeCellMe(row) },
        { data: null, title: langData['ot_date'] || 'OT Date', render: (d, t, row) => toDisplayDateMe(row.ot_date) },
        { data: 'hours', title: langData['hours'] || 'Hours', className: 'text-end' },
        { data: 'status', title: langData['status'] || 'Status', className: 'text-center', render: (d) => overtimeStatusBadge(d) },
        { data: 'data_source', title: langData['source'] || 'Source', className: 'text-center', render: (d) => sourceBadgeMe(d) },
        { data: null, title: '', orderable: false, className: 'text-end all', render: (d, t, row) => actionBtnsMe(`openOvertimeModal(${row.id})`, `askDeleteMe('overtime', ${row.id}, '${escapeHtmlMe(employeeNameMe(row))} - ${escapeHtmlMe(toDisplayDateMe(row.ot_date))}')`) },
    ],
};

const IMPORT_BATCH_DETAIL_FILTER_COLUMNS = {
    attendance: [{ index: 0, key: 'employee' }, { index: 1, key: 'work_date' }, { index: 2, key: 'status' }, { index: 3, key: 'data_source' }],
    leave: [{ index: 0, key: 'employee' }, { index: 1, key: 'start_date' }, { index: 2, key: 'end_date' }, { index: 3, key: 'status' }, { index: 4, key: 'data_source' }],
    overtime: [{ index: 0, key: 'employee' }, { index: 1, key: 'ot_date' }, { index: 2, key: 'hours' }, { index: 3, key: 'status' }, { index: 4, key: 'data_source' }],
};

function openImportBatchDetail(batchId, entityType) {
    $.ajax({
        url: `${BASE_URL}/api/manual-import.batch-detail`, method: 'GET', data: { batch_id: batchId, entity_type: entityType }, dataType: 'json',
        success: function (res) {
            if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
            currentImportBatchContext = { batchId, entityType };
            if (dtImportBatchDetail) { dtImportBatchDetail.destroy(); dtImportBatchDetail = null; }
            $('#tb_import_batch_detail thead').empty();
            $('#tb_import_batch_detail tbody').empty();
            dtImportBatchDetail = $('#tb_import_batch_detail').DataTable({
                responsive: true,
                data: res.data || [],
                columns: IMPORT_BATCH_DETAIL_COLUMNS[entityType],
                order: [], lengthChange: false, pageLength: pageLength, lengthMenu: lengthMenu,
                language: { ...getTableLang() },
                initComplete: function () {
                    initExcelColumnFilters(this.api(), { mode: 'client', columns: IMPORT_BATCH_DETAIL_FILTER_COLUMNS[entityType] });
                }
            });
            new bootstrap.Modal(document.getElementById('importBatchDetailModal')).show();
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
}

/** Keeps the batch-detail modal's own table in sync after an edit/delete made through it (see this section's own top comment). No-op when the modal isn't open, or open on a different entity type. */
function refreshImportBatchDetailIfOpen(entityType) {
    if (!currentImportBatchContext || currentImportBatchContext.entityType !== entityType) { return; }
    const { batchId } = currentImportBatchContext;
    $.ajax({
        url: `${BASE_URL}/api/manual-import.batch-detail`, method: 'GET', data: { batch_id: batchId, entity_type: entityType }, dataType: 'json',
        success: function (res) {
            if (res.status && dtImportBatchDetail) { dtImportBatchDetail.clear().rows.add(res.data || []).draw(false); }
        }
    });
}
$(document).on('hidden.bs.modal', '#importBatchDetailModal', function () { currentImportBatchContext = null; });

$(document).on('click', '#btnDownloadImportTemplate', downloadImportTemplate);
$(document).on('click', '#btnPreviewImport', previewImportFile);
$(document).on('click', '#btnConfirmImport', confirmImportCommit);

// 2026-08-30, History tab wiring -- same .station-filter toggle/clear-filter/change-reloads
// convention every other tab on this page already uses (see meFilterToggle()/
// updateMeClearFilterVisibility() above), lazy-inited on shown.bs.tab (this tab is not the default
// active one, same "DataTable inside a hidden Bootstrap tab collapses columns" precedent this app
// has hit and documented many times).
$(document).on('shown.bs.tab', '#import-history-tab', function () { renderImportHistory(); });
meFilterToggle('#importHistoryStationFilter', '#importHistoryStationFilterToggle');
$(document).on('change', '#filter_ih_event_type, #filter_ih_entity_type, #filter_ih_date_from, #filter_ih_date_to', function () {
    updateImportHistoryClearFilterVisibility();
    if (dtImportHistory) dtImportHistory.ajax.reload(null, true);
});
$(document).on('click', '#btnImportHistoryClearFilter', function () {
    $('#filter_ih_event_type, #filter_ih_entity_type').val(null).trigger('change');
    $('#filter_ih_date_from, #filter_ih_date_to').val('');
    updateImportHistoryClearFilterVisibility();
    if (dtImportHistory) dtImportHistory.ajax.reload(null, true);
});

$(function () {
    initDatepicker('#filter_att_date_from, #filter_att_date_to, #filter_leave_date_from, #filter_leave_date_to, #filter_ot_date_from, #filter_ot_date_to, #filter_ih_date_from, #filter_ih_date_to, #attendanceWorkDate, #leaveStartDate, #leaveEndDate, #overtimeDate');
    initSelect2('#filter_att_employee', { mode: 'ajax', allowClear: true });
    initSelect2('#filter_leave_employee', { mode: 'ajax', allowClear: true });
    initSelect2('#filter_ot_employee', { mode: 'ajax', allowClear: true });
    initSelect2('#importEntityType', { mode: 'static' });
    initSelect2('#filter_ih_event_type', { mode: 'static', allowClear: true });
    initSelect2('#filter_ih_entity_type', { mode: 'static', allowClear: true });
    renderAttendance();
    renderLeave();
    renderOvertime();
});
