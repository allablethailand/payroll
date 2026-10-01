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
function employeeNameMe(row) {
    return currentLang === 'th' ? row.employee_name_th : row.employee_name_en;
}
function employeeCellMe(row) {
    return `${escapeHtml(row.employee_no)} - ${escapeHtml(employeeNameMe(row))}`;
}
function plainTagMe(key, value) {
    return `<span class="text-muted">${escapeHtml(langData[key] || value)}</span>`;
}
function attendanceStatusBadge(status) {
    return statusBadgeHtml(status, 'attendance_status');
}
function leaveStatusBadge(status) {
    return statusBadgeHtml(status, 'approval_status');
}
function overtimeStatusBadge(status) {
    return statusBadgeHtml(status, 'approval_status');
}
function sourceBadgeMe(source) {
    return plainTagMe('source_' + source, source);
}
// 2026-09-02, explicit request: circular row-action buttons (see style.css's own
// ".btn-circle-action" section) replace the old adjacent .btn-group.
function actionBtnsMe(editFn, delFn) {
    return `
    <div class="d-flex gap-1 justify-content-center">
        <button class="btn btn-link btn-circle-action text-warning" onclick="${editFn}"><i class="fa-solid fa-pen-to-square"></i></button>
        <button class="btn btn-link btn-circle-action text-danger" onclick="${delFn}"><i class="fa-solid fa-trash-can"></i></button>
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
// 2026-09-02, explicit request: "อยากให้เพิ่ม ให้เพิ่มได้ทีละหลายรายการ" -- a SECOND button next to the
// existing single-record Add, opening the new fullscreen grid (openBulkEntryModal(), see
// public/js/manual-entry/bulk-entry.js) -- deliberately additive, not a replacement, so the existing
// single-Add flow (quick, one record) stays exactly as-is for whoever just wants that.
function addBulkButtonInitCompleteMe(btnClass, entityType) {
    return function () {
        const $wrapper = $(this.api().table().container());
        const $searchDiv = $wrapper.find('.dt-search');
        if ($searchDiv.find('.' + btnClass).length === 0) {
            $searchDiv.append(`<button type="button" class="btn btn-outline-primary ms-1 ${btnClass}" onclick="openBulkEntryModal('${entityType}')"><i class="fa-solid fa-table-cells me-1"></i><span data-i18n="bulk_entry_add_multiple">${langData['bulk_entry_add_multiple'] || 'Add Multiple'}</span></button>`);
        }
    };
}
// 2026-09-02, explicit follow-up request -- a 3rd top-level button (alongside Add/Add Multiple) that
// opens the import wizard DIRECTLY (openBulkImportModal(), bulk-entry.js), grid not required to
// already be open -- this is what replaced the old standalone Import tab, see that tab's own removal
// comment in manual-entry/index.php.
function addImportButtonInitCompleteMe(btnClass, entityType) {
    return function () {
        const $wrapper = $(this.api().table().container());
        const $searchDiv = $wrapper.find('.dt-search');
        if ($searchDiv.find('.' + btnClass).length === 0) {
            $searchDiv.append(`<button type="button" class="btn btn-outline-secondary ms-1 ${btnClass}" onclick="openBulkImportModal('${entityType}')"><i class="fa-solid fa-file-import me-1"></i><span data-i18n="bulk_entry_import_file">${langData['bulk_entry_import_file'] || 'Import File'}</span></button>`);
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
    dtAttendance = initSharedDataTable('#tb_attendance', {
        columnFilters: {
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
        },
        dtOptions: {
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
                { data: null, render: (d, t, row) => escapeHtml((currentLang === 'th' ? row.shift_name_th : row.shift_name_en) || '-') },
                { data: null, render: (d, t, row) => row.clock_in ? String(row.clock_in).substring(11, 16) : '-' },
                { data: null, render: (d, t, row) => row.clock_out ? String(row.clock_out).substring(11, 16) : '-' },
                { data: null, className: 'text-end', render: (d, t, row) => row.actual_work_minutes ? (row.actual_work_minutes / 60).toFixed(1) : '-' },
                { data: 'status', className: 'text-center', render: (d) => attendanceStatusBadge(d) },
                { data: 'data_source', className: 'text-center', render: (d) => sourceBadgeMe(d) },
                // 2026-08-28: className:'all' keeps this last actions column from collapsing into the
                // Responsive expand row.
                { data: null, orderable: false, className: 'text-end all', render: (d, t, row) => actionBtnsMe(`openAttendanceModal(${row.id})`, `askDeleteMe('attendance', ${row.id}, '${escapeHtml(employeeNameMe(row))} - ${escapeHtml(toDisplayDateMe(row.work_date))}')`) }
            ],
            ordering: false, lengthChange: false, pageLength: 10,
            language: { emptyTable: langData['no_attendance_yet'] || 'No attendance records have been added yet.' },
            initComplete: function () {
                addButtonInitCompleteMe('btn-add-attendance', 'fa-solid fa-plus', 'add_attendance', 'Attendance', 'openAttendanceModal()').call(this);
                addBulkButtonInitCompleteMe('btn-bulk-attendance', 'attendance').call(this);
                addImportButtonInitCompleteMe('btn-import-attendance', 'attendance').call(this);
            },
            searching: true,
        },
    });
}
// 2026-09-03, Manual Entry Phase 1A: true for the entire synchronous+async duration of an EDIT-mode
// modal load -- same "isLoadingEmployeeForm" guard precedent employee/detail.js already established
// for the identical race: without this, the Shift auto-fill handler on #attendanceEmployee's own
// `change` event (fired by this function's own `.trigger('change.select2')` when programmatically
// setting the employee for an EXISTING record) would fire an async lookup that could resolve AFTER
// this function has already set the record's own historically-saved shift_id, silently clobbering it
// with the employee's CURRENT master shift instead.
let isLoadingManualEntryModal = false;
function openAttendanceModal(id) {
    initSelect2('#attendanceEmployee', { mode: 'ajax' });
    initSelect2('#attendanceShift', { mode: 'ajax', allowClear: true });
    initSelect2('#attendanceStatus', { mode: 'static' });
    if (id) {
        isLoadingManualEntryModal = true;
        $.ajax({
            url: `${BASE_URL}/api/manual-attendance.get`, method: 'GET', data: { id }, dataType: 'json',
            success: function (res) {
                if (!res.status) { isLoadingManualEntryModal = false; showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
                const a = res.data;
                $('#attendanceId').val(a.id);
                $('#attendanceEmployee').empty().append(new Option(`${a.employee_no} - ${employeeNameMe(a)}`, a.employee_id, true, true)).trigger('change.select2');
                $('#attendanceWorkDate').val(toDisplayDateMe(a.work_date)).datepicker('update');
                if (a.shift_id) { $('#attendanceShift').empty().append(new Option((currentLang === 'th' ? a.shift_name_th : a.shift_name_en) || '', a.shift_id, true, true)).trigger('change.select2'); }
                else { $('#attendanceShift').empty().trigger('change.select2'); }
                $('#attendanceStatus').val(a.status).trigger('change.select2');
                $('#attendanceClockIn').val(a.clock_in ? String(a.clock_in).substring(11, 16) : '');
                $('#attendanceClockOut').val(a.clock_out ? String(a.clock_out).substring(11, 16) : '');
                $('#attendanceLateMinutes').val(a.late_minutes || 0);
                $('#attendanceEarlyMinutes').val(a.early_leave_minutes || 0);
                isLoadingManualEntryModal = false;
                new bootstrap.Modal(document.getElementById('attendanceModal')).show();
            },
            error: function () { isLoadingManualEntryModal = false; showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
        });
        return;
    }
    $('#attendanceId').val('');
    $('#attendanceEmployee').empty().trigger('change.select2');
    $('#attendanceShift').empty().trigger('change.select2');
    $('#attendanceWorkDate').val('').datepicker('update');
    $('#attendanceStatus').val('present').trigger('change.select2');
    $('#attendanceClockIn').val('');
    $('#attendanceClockOut').val('');
    $('#attendanceLateMinutes').val(0);
    $('#attendanceEarlyMinutes').val(0);
    new bootstrap.Modal(document.getElementById('attendanceModal')).show();
}
function saveAttendance(btnEl) {
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
    const $btn = btnEl ? $(btnEl) : $();
    setButtonLoading($btn, true);
    $.ajax({
        url: `${BASE_URL}/api/manual-attendance.save`, method: 'POST', contentType: 'application/json', data: JSON.stringify(payload), dataType: 'json',
        success: function (res) {
            setButtonLoading($btn, false);
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('attendanceModal')).hide();
                dtAttendance.ajax.reload(null, false);
                refreshImportBatchDetailIfOpen('attendance');
            } else { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
        },
        error: function () { setButtonLoading($btn, false); showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
}
// 2026-09-03, Manual Entry Phase 1A: auto-fill Shift from the selected employee's own master
// record -- the only field on this whole page with a real, direct master-data counterpart (see the
// audit that preceded this feature). Only fires on a genuine user pick (guarded by
// isLoadingManualEntryModal, see openAttendanceModal()'s own comment) and always REPLACES whatever
// was in the Shift field, since picking a different employee genuinely means "start over" for a
// field this tightly tied to who's selected -- still fully editable/clearable afterward, this is a
// convenience default, not a lock.
$(document).on('change', '#attendanceEmployee', function () {
    if (isLoadingManualEntryModal) return;
    const employeeId = $(this).val();
    if (!employeeId) { $('#attendanceShift').empty().trigger('change.select2'); return; }
    $.ajax({
        url: `${BASE_URL}/api/manual-entry.employee-context`, method: 'GET', data: { employee_id: employeeId }, dataType: 'json',
        success: function (res) {
            if (res.status && res.data && res.data.shift_id) {
                const label = (currentLang === 'th' ? res.data.shift_name_th : res.data.shift_name_en) || '';
                $('#attendanceShift').empty().append(new Option(label, res.data.shift_id, true, true)).trigger('change.select2');
            } else {
                $('#attendanceShift').empty().trigger('change.select2');
            }
        }
    });
});

/* ==================== LEAVE ==================== */
let dtLeave;
function renderLeave() {
    if ($.fn.DataTable.isDataTable('#tb_leave')) { dtLeave.ajax.reload(null, false); return; }
    dtLeave = initSharedDataTable('#tb_leave', {
        columnFilters: {
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
        },
        dtOptions: {
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
                { data: null, render: (d, t, row) => escapeHtml(currentLang === 'th' ? row.leave_type_name_th : row.leave_type_name_en) },
                { data: null, render: (d, t, row) => toDisplayDateMe(row.start_date) },
                { data: null, render: (d, t, row) => toDisplayDateMe(row.end_date) },
                { data: 'total_days', className: 'text-end' },
                { data: 'status', className: 'text-center', render: (d) => leaveStatusBadge(d) },
                { data: 'data_source', className: 'text-center', render: (d) => sourceBadgeMe(d) },
                // 2026-08-28: className:'all' keeps this last actions column from collapsing into the
                // Responsive expand row.
                { data: null, orderable: false, className: 'text-end all', render: (d, t, row) => actionBtnsMe(`openLeaveModal(${row.id})`, `askDeleteMe('leave', ${row.id}, '${escapeHtml(employeeNameMe(row))} - ${escapeHtml(toDisplayDateMe(row.start_date))}')`) }
            ],
            ordering: false, lengthChange: false, pageLength: 10,
            language: { emptyTable: langData['no_leave_yet'] || 'No leave records have been added yet.' },
            initComplete: function () {
                addButtonInitCompleteMe('btn-add-leave', 'fa-solid fa-plus', 'add_leave', 'Leave', 'openLeaveModal()').call(this);
                addBulkButtonInitCompleteMe('btn-bulk-leave', 'leave').call(this);
                addImportButtonInitCompleteMe('btn-import-leave', 'leave').call(this);
            },
            searching: true,
        },
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
                $('#leaveStartDate').val(toDisplayDateMe(l.start_date)).datepicker('update');
                $('#leaveEndDate').val(toDisplayDateMe(l.end_date)).datepicker('update');
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
    $('#leaveStartDate').val('').datepicker('update');
    $('#leaveEndDate').val('').datepicker('update');
    $('#leaveTotalDays').val('');
    $('#leaveStatus').val('approved').trigger('change.select2');
    $('#leaveReason').val('');
    new bootstrap.Modal(document.getElementById('leaveModal')).show();
}
function saveLeave(btnEl) {
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
    const $btn = btnEl ? $(btnEl) : $();
    setButtonLoading($btn, true);
    $.ajax({
        url: `${BASE_URL}/api/manual-leave.save`, method: 'POST', contentType: 'application/json', data: JSON.stringify(payload), dataType: 'json',
        success: function (res) {
            setButtonLoading($btn, false);
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('leaveModal')).hide();
                dtLeave.ajax.reload(null, false);
                refreshImportBatchDetailIfOpen('leave');
            } else { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
        },
        error: function () { setButtonLoading($btn, false); showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
}

/* ==================== OVERTIME ==================== */
let dtOvertime;
function renderOvertime() {
    if ($.fn.DataTable.isDataTable('#tb_overtime')) { dtOvertime.ajax.reload(null, false); return; }
    dtOvertime = initSharedDataTable('#tb_overtime', {
        columnFilters: {
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
        },
        dtOptions: {
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
                { data: null, render: (d, t, row) => escapeHtml(currentLang === 'th' ? row.ot_name_th : row.ot_name_en) },
                { data: null, render: (d, t, row) => toDisplayDateMe(row.ot_date) },
                { data: 'hours', className: 'text-end' },
                { data: null, className: 'text-end', render: (d, t, row) => row.amount !== null ? fmtNum(Number(row.amount)) : '-' },
                { data: 'status', className: 'text-center', render: (d) => overtimeStatusBadge(d) },
                { data: 'data_source', className: 'text-center', render: (d) => sourceBadgeMe(d) },
                // 2026-08-28: className:'all' keeps this last actions column from collapsing into the
                // Responsive expand row.
                { data: null, orderable: false, className: 'text-end all', render: (d, t, row) => actionBtnsMe(`openOvertimeModal(${row.id})`, `askDeleteMe('overtime', ${row.id}, '${escapeHtml(employeeNameMe(row))} - ${escapeHtml(toDisplayDateMe(row.ot_date))}')`) }
            ],
            ordering: false, lengthChange: false, pageLength: 10,
            language: { emptyTable: langData['no_overtime_yet'] || 'No overtime records have been added yet.' },
            initComplete: function () {
                addButtonInitCompleteMe('btn-add-overtime', 'fa-solid fa-plus', 'add_overtime', 'Overtime', 'openOvertimeModal()').call(this);
                addBulkButtonInitCompleteMe('btn-bulk-overtime', 'overtime').call(this);
                addImportButtonInitCompleteMe('btn-import-overtime', 'overtime').call(this);
            },
            searching: true,
        },
    });
}
function openOvertimeModal(id) {
    initSelect2('#overtimeEmployee', { mode: 'ajax' });
    initSelect2('#overtimeRate', { mode: 'ajax' });
    initSelect2('#overtimeStatus', { mode: 'static' });
    if (id) {
        isLoadingManualEntryModal = true;
        $.ajax({
            url: `${BASE_URL}/api/manual-overtime.get`, method: 'GET', data: { id }, dataType: 'json',
            success: function (res) {
                if (!res.status) { isLoadingManualEntryModal = false; showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
                const o = res.data;
                $('#overtimeId').val(o.id);
                $('#overtimeEmployee').empty().append(new Option(`${o.employee_no} - ${employeeNameMe(o)}`, o.employee_id, true, true)).trigger('change.select2');
                $('#overtimeRate').attr('data-employee-id', o.employee_id).empty().append(new Option(currentLang === 'th' ? o.ot_name_th : o.ot_name_en, o.ot_rate_id, true, true)).trigger('change.select2');
                $('#overtimeDate').val(toDisplayDateMe(o.ot_date)).datepicker('update');
                $('#overtimeHours').val(o.hours);
                $('#overtimeAmount').val(o.amount !== null ? o.amount : '');
                $('#overtimeStatus').val(o.status).trigger('change.select2');
                isLoadingManualEntryModal = false;
                new bootstrap.Modal(document.getElementById('overtimeModal')).show();
            },
            error: function () { isLoadingManualEntryModal = false; showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
        });
        return;
    }
    $('#overtimeId').val('');
    $('#overtimeEmployee').empty().trigger('change.select2');
    $('#overtimeRate').removeAttr('data-employee-id').empty().trigger('change.select2');
    $('#overtimeDate').val('').datepicker('update');
    $('#overtimeHours').val('');
    $('#overtimeAmount').val('');
    $('#overtimeStatus').val('approved').trigger('change.select2');
    new bootstrap.Modal(document.getElementById('overtimeModal')).show();
}
function saveOvertime(btnEl) {
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
    const $btn = btnEl ? $(btnEl) : $();
    setButtonLoading($btn, true);
    $.ajax({
        url: `${BASE_URL}/api/manual-overtime.save`, method: 'POST', contentType: 'application/json', data: JSON.stringify(payload), dataType: 'json',
        success: function (res) {
            setButtonLoading($btn, false);
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('overtimeModal')).hide();
                dtOvertime.ajax.reload(null, false);
                refreshImportBatchDetailIfOpen('overtime');
            } else { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
        },
        error: function () { setButtonLoading($btn, false); showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
}
// 2026-09-03, Manual Entry Phase 1A: scopes the OT Rate dropdown to the selected employee's own
// resolved OT Rate Set (MasterModel::master()'s 'ot_rate' case reads data-employee-id fresh on every
// search, see input.js) instead of listing every active Set's items company-wide -- there's no
// single correct OT Rate to auto-SELECT (a Set has multiple items, one per scope type: weekday/
// weekend/holiday/etc., and which applies depends on the specific overtime event being entered), so
// filtering the choices is the honest "reduce manual work" equivalent here, not a forced pick.
// Clears the current selection on a genuine employee change (guarded by isLoadingManualEntryModal,
// same as Attendance's own Shift handler) since a rate from a DIFFERENT employee's Set may no longer
// be a valid/sensible choice.
$(document).on('change', '#overtimeEmployee', function () {
    if (isLoadingManualEntryModal) return;
    const employeeId = $(this).val();
    if (employeeId) {
        $('#overtimeRate').attr('data-employee-id', employeeId);
    } else {
        $('#overtimeRate').removeAttr('data-employee-id');
    }
    $('#overtimeRate').empty().trigger('change.select2');
});

/* ==================== IMPORT (2026-08-30, Phase 5, T030-T035) ====================
 * 2026-09-02: the standalone Import TAB this section used to also serve was removed (superseded by
 * openBulkImportModal() in bulk-entry.js, see that file's own docblock) -- importEntityLabel() and
 * importRowStatusBadge() stay here since the History tab's own DataTable render (below) and the new
 * import modal both still use them; everything else that was Import-tab-specific (preview/commit/
 * download-template/renderImportPreviewResults wiring) moved into bulk-entry.js instead of being
 * duplicated, or was deleted outright where the new modal fully replaced it. */
function importEntityLabel(type) {
    return langData[type] || type;
}

function importRowStatusBadge(row) {
    if (row.status === 'error') { return statusBadgeHtml('error', 'import_activity_status'); }
    if (row.source_conflict) { return statusBadgeHtml('conflict', 'import_activity_status'); }
    return statusBadgeHtml('ok', 'import_activity_status');
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
    return plainTagMe(eventType, eventType);
}
function importHistoryStatusBadge(row) {
    if (row.event_type === 'download') { return statusBadgeHtml('ok', 'import_activity_status'); }
    return statusBadgeHtml(row.status, 'import_activity_status');
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
// The filter-bar's "no filter" option is 'all'; the API expects it empty.
function meIhFilterValue(selector) {
    const v = $(selector).val();
    return v === 'all' ? '' : (v || '');
}
function renderImportHistory() {
    if ($.fn.DataTable.isDataTable('#tb_import_history')) { dtImportHistory.ajax.reload(null, false); return; }
    dtImportHistory = initSharedDataTable('#tb_import_history', {
        columnFilters: {
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
        },
        dtOptions: {
            responsive: true,
            ajax: {
                url: `${BASE_URL}/api/manual-import.activity-log`, dataSrc: 'data',
                data: function (d) {
                    d.event_type = meIhFilterValue('#filter_ih_event_type');
                    d.entity_type = meIhFilterValue('#filter_ih_entity_type');
                    d.date_from = toIsoDateMe($('#filter_ih_date_from').val());
                    d.date_to = toIsoDateMe($('#filter_ih_date_to').val());
                }
            },
            columns: [
                { data: 'performed_at', render: { display: (v) => v ? String(v).replace('T', ' ').substring(0, 16) : '-', sort: (v) => v || '', filter: (v) => v || '' } },
                { data: 'event_type', className: 'text-center', render: (d) => importEventTypeBadge(d) },
                { data: null, render: (d, t, row) => escapeHtml(importEntityLabel(row.entity_type)) },
                { data: null, render: (d, t, row) => escapeHtml(importHistoryByLabel(row)) },
                { data: null, render: (d, t, row) => escapeHtml(importHistoryDeviceLabel(row)) },
                { data: null, render: (d, t, row) => escapeHtml(importHistoryBrowserLabel(row)) },
                { data: 'ip_address', render: (v) => escapeHtml(v || '-') },
                { data: null, className: 'text-center', render: (d, t, row) => importHistoryStatusBadge(row) },
                // 2026-09-02, explicit request: circular row-action buttons (see style.css's own
                // ".btn-circle-action" section) replace the old adjacent .btn-group.
                // Platform Hardening Phase 5C: a Download button for the original uploaded file --
                // row.original_file_name is only ever set on an 'import' row that has one (NULL for
                // 'download' rows and for any import batch committed before this column existed).
                { data: null, orderable: false, className: 'text-end all', render: (d, t, row) => row.event_type === 'import'
                    ? `<div class="d-flex gap-1 justify-content-end">
                        <button class="btn btn-link btn-circle-action text-primary" onclick="openImportBatchDetail(${row.id}, '${row.entity_type}')"><i class="fa-solid fa-eye"></i></button>
                        ${row.original_file_name ? `<a href="${BASE_URL}/api/manual-import.download-original?batch_id=${row.id}" class="btn btn-link btn-circle-action text-secondary" title="${escapeHtml(row.original_file_name)}"><i class="fa-solid fa-download"></i></a>` : ''}
                       </div>`
                    : '' },
            ],
            order: [[0, 'desc']],
            language: { emptyTable: langData['no_import_batches_yet'] || 'No imports have been run yet.' },
            searching: true,
        },
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
        { data: null, title: '', orderable: false, className: 'text-end all', render: (d, t, row) => actionBtnsMe(`openAttendanceModal(${row.id})`, `askDeleteMe('attendance', ${row.id}, '${escapeHtml(employeeNameMe(row))} - ${escapeHtml(toDisplayDateMe(row.work_date))}')`) },
    ],
    leave: [
        { data: null, title: langData['employee'] || 'Employee', render: (d, t, row) => employeeCellMe(row) },
        { data: null, title: langData['start_date'] || 'Start Date', render: (d, t, row) => toDisplayDateMe(row.start_date) },
        { data: null, title: langData['end_date'] || 'End Date', render: (d, t, row) => toDisplayDateMe(row.end_date) },
        { data: 'status', title: langData['status'] || 'Status', className: 'text-center', render: (d) => leaveStatusBadge(d) },
        { data: 'data_source', title: langData['source'] || 'Source', className: 'text-center', render: (d) => sourceBadgeMe(d) },
        { data: null, title: '', orderable: false, className: 'text-end all', render: (d, t, row) => actionBtnsMe(`openLeaveModal(${row.id})`, `askDeleteMe('leave', ${row.id}, '${escapeHtml(employeeNameMe(row))} - ${escapeHtml(toDisplayDateMe(row.start_date))}')`) },
    ],
    overtime: [
        { data: null, title: langData['employee'] || 'Employee', render: (d, t, row) => employeeCellMe(row) },
        { data: null, title: langData['ot_date'] || 'OT Date', render: (d, t, row) => toDisplayDateMe(row.ot_date) },
        { data: 'hours', title: langData['hours'] || 'Hours', className: 'text-end' },
        { data: 'status', title: langData['status'] || 'Status', className: 'text-center', render: (d) => overtimeStatusBadge(d) },
        { data: 'data_source', title: langData['source'] || 'Source', className: 'text-center', render: (d) => sourceBadgeMe(d) },
        { data: null, title: '', orderable: false, className: 'text-end all', render: (d, t, row) => actionBtnsMe(`openOvertimeModal(${row.id})`, `askDeleteMe('overtime', ${row.id}, '${escapeHtml(employeeNameMe(row))} - ${escapeHtml(toDisplayDateMe(row.ot_date))}')`) },
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
            dtImportBatchDetail = initSharedDataTable('#tb_import_batch_detail', {
                columnFilters: { mode: 'client', columns: IMPORT_BATCH_DETAIL_FILTER_COLUMNS[entityType] },
                dtOptions: {
                    responsive: true,
                    data: res.data || [],
                    columns: IMPORT_BATCH_DETAIL_COLUMNS[entityType],
                    order: [], lengthChange: false,
                    searching: true,
                },
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

// History tab is lazy-inited on shown.bs.tab (DataTable inside a hidden tab collapses columns).
$(document).on('shown.bs.tab', '#import-history-tab', function () { renderImportHistory(); });

$(function () {
    initDatepicker('#filter_att_date_from, #filter_att_date_to, #filter_leave_date_from, #filter_leave_date_to, #filter_ot_date_from, #filter_ot_date_to, #filter_ih_date_from, #filter_ih_date_to, #attendanceWorkDate, #leaveStartDate, #leaveEndDate, #overtimeDate');
    initSelect2('#filter_att_employee', { mode: 'ajax', allowClear: true });
    initSelect2('#filter_leave_employee', { mode: 'ajax', allowClear: true });
    initSelect2('#filter_ot_employee', { mode: 'ajax', allowClear: true });
    initSelect2('#filter_ih_event_type', { mode: 'static' });
    initSelect2('#filter_ih_entity_type', { mode: 'static' });
    renderAttendance();
    renderLeave();
    renderOvertime();
    initFilterBar('#attendanceFilterBar', { onChange: function () { if (dtAttendance) dtAttendance.ajax.reload(null, true); } });
    initFilterBar('#leaveFilterBar', { onChange: function () { if (dtLeave) dtLeave.ajax.reload(null, true); } });
    initFilterBar('#overtimeFilterBar', { onChange: function () { if (dtOvertime) dtOvertime.ajax.reload(null, true); } });
    initFilterBar('#importHistoryFilterBar', { onChange: function () { if (dtImportHistory) dtImportHistory.ajax.reload(null, true); } });
});
