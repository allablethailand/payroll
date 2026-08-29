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
function ajaxDeleteMe(url, table) {
    $.ajax({
        url: `${BASE_URL}${url}`, method: 'POST', data: { id: deleteContextMe.id }, dataType: 'json',
        success: function (res) {
            bootstrap.Modal.getInstance(document.getElementById('manualEntryDeleteModal')).hide();
            if (res.status) { showSuccess(res.message || langData['delete_success'] || 'Deleted successfully.'); table.ajax.reload(null, false); }
            else { showWarning(res.message || langData['delete_failed'] || 'Failed to delete.'); }
        },
        error: function () { bootstrap.Modal.getInstance(document.getElementById('manualEntryDeleteModal')).hide(); showWarning(langData['delete_failed'] || 'Failed to delete.'); }
    });
}
function confirmManualEntryDelete() {
    if (!deleteContextMe) return;
    const { type } = deleteContextMe;
    if (type === 'attendance') { ajaxDeleteMe('/api/manual-attendance.delete', dtAttendance); }
    else if (type === 'leave') { ajaxDeleteMe('/api/manual-leave.delete', dtLeave); }
    else if (type === 'overtime') { ajaxDeleteMe('/api/manual-overtime.delete', dtOvertime); }
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
            { data: null, render: (d, t, row) => row.actual_work_minutes ? (row.actual_work_minutes / 60).toFixed(1) : '-' },
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
            } else { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
}

$(document).on('change', '#filter_att_employee, #filter_att_date_from, #filter_att_date_to', function () {
    if (dtAttendance) dtAttendance.ajax.reload(null, true);
});
$(document).on('change', '#filter_leave_employee, #filter_leave_date_from, #filter_leave_date_to', function () {
    if (dtLeave) dtLeave.ajax.reload(null, true);
});
$(document).on('change', '#filter_ot_employee, #filter_ot_date_from, #filter_ot_date_to', function () {
    if (dtOvertime) dtOvertime.ajax.reload(null, true);
});

$(function () {
    initDatepicker('#filter_att_date_from, #filter_att_date_to, #filter_leave_date_from, #filter_leave_date_to, #filter_ot_date_from, #filter_ot_date_to, #attendanceWorkDate, #leaveStartDate, #leaveEndDate, #overtimeDate');
    initSelect2('#filter_att_employee', { mode: 'ajax', allowClear: true });
    initSelect2('#filter_leave_employee', { mode: 'ajax', allowClear: true });
    initSelect2('#filter_ot_employee', { mode: 'ajax', allowClear: true });
    renderAttendance();
    renderLeave();
    renderOvertime();
});
