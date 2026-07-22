/**
 * Setup & Rules page. Shift, Holiday, Work Location, Leave Type, and OT Rate are all wired to
 * real backends (SetupRulesController).
 *
 * All DataTables here rely on DataTables' own built-in search box (default `dom`, no override)
 * with the "Add" button injected into `.dt-search` via `initComplete`, matching the convention
 * used by Approval Workflow / Payroll Cycle / Employee list -- NOT a hand-written search box.
 */
let deleteContext = null;

function statusSwitch(checked, onchange) {
    return `<div class="form-check form-switch d-flex justify-content-center m-0">
        <input class="form-check-input" type="checkbox" ${checked ? 'checked' : ''} onchange="${onchange}">
    </div>`;
}
function actionBtns(editFn, delFn) {
    return `
    <div class="btn-group border rounded-3 bg-white">
        <button class="btn btn-link text-warning" onclick="${editFn}"><i class="fa-solid fa-pen-to-square"></i></button>
        <button class="btn btn-link py-1 text-danger border-start" onclick="${delFn}"><i class="fa-solid fa-trash-can"></i></button>
    </div>`;
}
function actionBtnsShift(editFn, assignFn, delFn) {
    return `
    <div class="btn-group border rounded-3 bg-white">
        <button class="btn btn-link text-primary" title="${langData['assign_employees'] || 'Assign Employees'}" onclick="${assignFn}"><i class="fa-solid fa-user-check"></i></button>
        <button class="btn btn-link text-warning border-start" onclick="${editFn}"><i class="fa-solid fa-pen-to-square"></i></button>
        <button class="btn btn-link py-1 text-danger border-start" onclick="${delFn}"><i class="fa-solid fa-trash-can"></i></button>
    </div>`;
}
function escapeHtmlSr(str) {
    return $('<div>').text(str || '').html().replace(/"/g, '&quot;');
}
function fmtDate(d) {
    const dt = new Date(d + "T00:00:00");
    return dt.toLocaleDateString(currentLang === 'th' ? 'th-TH' : 'en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}
function addButtonInitComplete(btnClass, iconClass, labelKey, labelFallback, onClickFnName) {
    return function () {
        const $wrapper = $(this.api().table().container());
        const $searchDiv = $wrapper.find('.dt-search');
        if ($searchDiv.find('.' + btnClass).length === 0) {
            $searchDiv.append(`<button type="button" class="btn btn-primary ms-1 ${btnClass}" onclick="${onClickFnName}"><i class="${iconClass} me-1"></i><span>${langData[labelKey] || labelFallback}</span></button>`);
        }
    };
}
function askDelete(type, id, name) {
    deleteContext = { type, id, name };
    $('#deleteTargetName').text(name);
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
function ajaxDelete(url, table) {
    $.ajax({
        url: `${BASE_URL}${url}`, method: 'POST', data: { id: deleteContext.id }, dataType: 'json',
        success: function (res) {
            bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
            if (res.status) { showSuccess(res.message || langData['delete_success'] || 'Deleted successfully.'); table.ajax.reload(null, false); }
            else { showWarning(res.message || langData['delete_failed'] || 'Failed to delete.'); }
        },
        error: function () { bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide(); showWarning(langData['delete_failed'] || 'Failed to delete.'); }
    });
}
function confirmDelete() {
    if (!deleteContext) return;
    const { type } = deleteContext;
    if (type === 'shift') { ajaxDelete('/api/shift.delete', dtShift); }
    else if (type === 'holiday') { ajaxDelete('/api/holiday.delete', dtHoliday); }
    else if (type === 'location') { ajaxDelete('/api/work-location.delete', dtWorkLocation); }
    else if (type === 'leave') { ajaxDelete('/api/leave-type.delete', dtLeave); }
    else if (type === 'ot') { ajaxDelete('/api/ot-rate.delete', dtOt); }
    deleteContext = null;
}

/* ==================== SHIFT ==================== */
let dtShift;
function renderShift() {
    if ($.fn.DataTable.isDataTable('#tb_shift')) { $('#tb_shift').DataTable().ajax.reload(null, false); return; }
    dtShift = $('#tb_shift').DataTable({
        ajax: { url: `${BASE_URL}/api/shift.list`, dataSrc: 'data' },
        columns: [
            { data: null, render: (d, t, row) => `<div class="row-name">${escapeHtmlSr(currentLang === 'th' ? row.shift_name_th : row.shift_name_en)}</div>` },
            { data: 'shift_code', render: d => `<span class="row-code">${escapeHtmlSr(d)}</span>` },
            { data: null, render: (d, t, row) => `<span class="text-faint"><i class="fa-regular fa-clock me-1"></i>${(row.start_time || '').slice(0, 5)} - ${(row.end_time || '').slice(0, 5)}</span>` },
            { data: null, render: (d, t, row) => `<span class="text-faint">${row.location_name_th ? escapeHtmlSr(currentLang === 'th' ? row.location_name_th : row.location_name_en) : '-'}</span>` },
            { data: null, render: (d, t, row) => `<span class="text-faint">${row.updated_at ? fmtDate(row.updated_at.slice(0, 10)) : fmtDate(row.created_at.slice(0, 10))}</span>` },
            { data: 'status', className: 'text-center', render: (d, t, row) => statusSwitch(d === 'active', `toggleShiftStatus(${row.id})`) },
            { data: null, orderable: false, className: 'text-end', render: (d, t, row) => actionBtnsShift(`openShiftModal(${row.id})`, `openShiftAssignModal(${row.id}, '${escapeHtmlSr(currentLang === 'th' ? row.shift_name_th : row.shift_name_en)}')`, `askDelete('shift', ${row.id}, '${escapeHtmlSr(currentLang === 'th' ? row.shift_name_th : row.shift_name_en)}')`) }
        ],
        ordering: false, lengthChange: false, pageLength: 10,
        language: { ...getTableLang(), emptyTable: langData['no_shifts_yet'] || 'No shifts have been added yet.' },
        initComplete: addButtonInitComplete('btn-add-shift', 'fa-solid fa-plus', 'add_shift', 'Add Shift', 'openShiftModal()')
    });
}
function toggleShiftStatus(id) {
    $.ajax({
        url: `${BASE_URL}/api/shift.toggle-status`, method: 'POST', data: { id }, dataType: 'json',
        success: function (res) {
            if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
            dtShift.ajax.reload(null, false);
        }
    });
}
function openShiftModal(id) {
    $('#shiftModalTitle').html(`<i class="fa-regular fa-calendar-days"></i> <span data-i18n="shift">${langData['shift'] || 'Shift'}</span>`);
    initSelect2('#shiftWorkLocation', { mode: 'ajax', allowClear: true });
    if (id) {
        $.ajax({
            url: `${BASE_URL}/api/shift.get`, method: 'GET', data: { id }, dataType: 'json',
            success: function (res) {
                if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
                const s = res.data;
                $('#shiftId').val(s.id);
                $('#shiftName').val(currentLang === 'th' ? s.shift_name_th : s.shift_name_en);
                $('#shiftCode').val(s.shift_code);
                $('#shiftDesc').val(s.description || '');
                $('#shiftStart').val((s.start_time || '').slice(0, 5));
                $('#shiftEnd').val((s.end_time || '').slice(0, 5));
                $('#shiftBreak').val(s.break_minutes || 0);
                $('#shiftStatus').prop('checked', s.status === 'active');
                const $loc = $('#shiftWorkLocation');
                $loc.empty();
                if (s.work_location_id) {
                    const label = (currentLang === 'th' ? s.location_name_th : s.location_name_en) || s.location_name_th;
                    $loc.append(new Option(label, s.work_location_id, true, true));
                }
                $loc.trigger('change.select2');
                new bootstrap.Modal(document.getElementById('shiftModal')).show();
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
        });
        return;
    }
    $('#shiftId').val(''); $('#shiftName').val(''); $('#shiftCode').val('');
    $('#shiftDesc').val(''); $('#shiftStart').val('08:00'); $('#shiftEnd').val('17:00'); $('#shiftBreak').val(0);
    $('#shiftWorkLocation').empty().trigger('change.select2');
    $('#shiftStatus').prop('checked', true);
    new bootstrap.Modal(document.getElementById('shiftModal')).show();
}
function saveShift() {
    const name = $('#shiftName').val().trim(), code = $('#shiftCode').val().trim();
    if (!name || !code) { showWarning(langData['required_star_message'] || 'Please fill all fields marked with *'); return; }
    const payload = {
        id: $('#shiftId').val() || null,
        shift_name_th: name, shift_name_en: name, shift_code: code,
        description: $('#shiftDesc').val().trim(),
        start_time: $('#shiftStart').val(), end_time: $('#shiftEnd').val(),
        break_minutes: parseInt($('#shiftBreak').val()) || 0,
        work_location_id: $('#shiftWorkLocation').val() || null,
        status: $('#shiftStatus').is(':checked') ? 'active' : 'inactive'
    };
    $.ajax({
        url: `${BASE_URL}/api/shift.save`, method: 'POST', contentType: 'application/json', data: JSON.stringify(payload), dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('shiftModal')).hide();
                dtShift.ajax.reload(null, false);
            } else { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
}
function openShiftAssignModal(id, name) {
    $('#shiftAssignModalTitle').html(`<i class="fa-solid fa-user-check"></i> <span>${langData['assign_employees'] || 'Assign Employees'}</span> - ${escapeHtmlSr(name)}`);
    $('#shiftAssignId').val(id);
    initSelect2('#shiftAssignEmployees', { mode: 'ajax' });
    const $sel = $('#shiftAssignEmployees');
    $.ajax({
        url: `${BASE_URL}/api/shift.assigned-employees`, method: 'GET', data: { id }, dataType: 'json',
        success: function (res) {
            $sel.empty();
            if (res.status) {
                (res.data || []).forEach(e => {
                    const label = (currentLang === 'th' ? e.text_th : e.text_en) || e.text_th;
                    $sel.append(new Option(label, e.id, true, true));
                });
            }
            $sel.trigger('change.select2');
            new bootstrap.Modal(document.getElementById('shiftAssignModal')).show();
        }
    });
}
function saveShiftAssignment() {
    const shiftId = $('#shiftAssignId').val();
    const employeeIds = ($('#shiftAssignEmployees').val() || []).map(v => parseInt(v));
    $.ajax({
        url: `${BASE_URL}/api/shift.assign-employees`, method: 'POST', contentType: 'application/json',
        data: JSON.stringify({ shift_id: parseInt(shiftId), employee_ids: employeeIds }), dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('shiftAssignModal')).hide();
                dtShift.ajax.reload(null, false);
            } else { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
}

/* ==================== HOLIDAY ==================== */
let dtHoliday;
const HOLIDAY_SCOPE_TYPES = ['shift', 'department', 'position', 'employee'];
function holidayScopeSelector(type) {
    return '#holidayScope' + type.charAt(0).toUpperCase() + type.slice(1);
}
function holidayScopeSummary(row) {
    const modeLabel = row.assignment_mode === 'exclude' ? (langData['exclude_mode'] || 'Exclude') : (langData['include_mode'] || 'Include');
    const modeCls = row.assignment_mode === 'exclude' ? 'badge-unpaid' : 'badge-paid';
    const count = parseInt(row.assignment_count || 0);
    const countText = count > 0 ? `${count} ${langData['scopes_selected'] || 'scope(s) selected'}` : (langData['no_scope_selected'] || 'No specific scope');
    return `<span class="badge-soft ${modeCls} me-1">${modeLabel.split(' (')[0]}</span><span class="text-faint">${countText}</span>`;
}
function initHolidayScopeSelects() {
    initSelect2('#holidayRecurring', { mode: 'static' });
    initSelect2('#holidayMode', { mode: 'static' });
    HOLIDAY_SCOPE_TYPES.forEach(type => initSelect2(holidayScopeSelector(type), { mode: 'ajax' }));
    $('#holidayMode').off('change.holidayHint').on('change.holidayHint', updateHolidayModeHint);
}
function updateHolidayModeHint() {
    const mode = $('#holidayMode').val();
    $('#holidayModeHint').text(mode === 'exclude' ? (langData['exclude_mode_hint'] || '') : (langData['include_mode_hint'] || ''));
}
function renderHoliday() {
    if ($.fn.DataTable.isDataTable('#tb_holiday')) { $('#tb_holiday').DataTable().ajax.reload(null, false); return; }
    dtHoliday = $('#tb_holiday').DataTable({
        ajax: { url: `${BASE_URL}/api/holiday.list`, dataSrc: 'data' },
        columns: [
            { data: null, render: (d, t, row) => `<div class="row-name">${escapeHtmlSr(currentLang === 'th' ? row.name_th : row.name_en)}</div>` },
            { data: null, render: (d, t, row) => `<span class="text-faint"><i class="fa-regular fa-calendar me-1"></i>${fmtDate(row.holiday_date)}</span>` },
            { data: null, render: (d, t, row) => parseInt(row.is_recurring) === 1 ? `<span class="badge-soft badge-paid">${langData['recurring_every_year'] || 'Recurring'}</span>` : `<span class="badge-soft badge-unpaid">${langData['one_time_only'] || 'One-time'}</span>` },
            { data: null, render: (d, t, row) => holidayScopeSummary(row) },
            { data: 'status', className: 'text-center', render: (d, t, row) => statusSwitch(d === 'active', `toggleHolidayStatus(${row.id})`) },
            { data: null, orderable: false, className: 'text-end', render: (d, t, row) => actionBtns(`openHolidayModal(${row.id})`, `askDelete('holiday', ${row.id}, '${escapeHtmlSr(currentLang === 'th' ? row.name_th : row.name_en)}')`) }
        ],
        ordering: false, lengthChange: false, pageLength: 10,
        language: { ...getTableLang(), emptyTable: langData['no_holidays_found'] || 'No holidays found.' },
        initComplete: addButtonInitComplete('btn-add-holiday', 'fa-solid fa-plus', 'add_holiday', 'Add Holiday', 'openHolidayModal()')
    });
}
function toggleHolidayStatus(id) {
    $.ajax({
        url: `${BASE_URL}/api/holiday.toggle-status`, method: 'POST', data: { id }, dataType: 'json',
        success: function (res) {
            if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
            dtHoliday.ajax.reload(null, false);
        }
    });
}
function resetHolidayScopeSelects() {
    HOLIDAY_SCOPE_TYPES.forEach(type => { $(holidayScopeSelector(type)).empty().trigger('change.select2'); });
}
function populateHolidayScopeSelects(assignments) {
    const grouped = { shift: [], department: [], position: [], employee: [] };
    (assignments || []).forEach(a => { if (grouped[a.scope_type]) grouped[a.scope_type].push(a); });
    HOLIDAY_SCOPE_TYPES.forEach(type => {
        const $sel = $(holidayScopeSelector(type));
        $sel.empty();
        grouped[type].forEach(a => {
            const label = (currentLang === 'th' ? a.text_th : a.text_en) || a.text_th || a.text_en;
            $sel.append(new Option(label, a.scope_id, true, true));
        });
        $sel.trigger('change.select2');
    });
}
function openHolidayModal(id) {
    $('#holidayModalTitle').html(`<i class="fa-solid fa-calendar-day"></i> <span data-i18n="holiday">${langData['holiday'] || 'Holiday'}</span>`);
    if (id) {
        $.ajax({
            url: `${BASE_URL}/api/holiday.get`, method: 'GET', data: { id }, dataType: 'json',
            success: function (res) {
                if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
                const h = res.data;
                $('#holidayId').val(h.id);
                $('#holidayNameTh').val(h.name_th); $('#holidayNameEn').val(h.name_en);
                $('#holidayDate').val(h.holiday_date);
                $('#holidayRecurring').val(String(h.is_recurring)).trigger('change.select2');
                $('#holidayMode').val(h.assignment_mode).trigger('change.select2');
                $('#holidayRemark').val(h.remark || '');
                $('#holidayStatus').prop('checked', h.status === 'active');
                populateHolidayScopeSelects(h.assignments);
                updateHolidayModeHint();
                new bootstrap.Modal(document.getElementById('holidayModal')).show();
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
        });
        return;
    }
    $('#holidayId').val('');
    $('#holidayNameTh').val(''); $('#holidayNameEn').val('');
    $('#holidayDate').val('');
    $('#holidayRecurring').val('1').trigger('change.select2');
    $('#holidayMode').val('include').trigger('change.select2');
    $('#holidayRemark').val('');
    $('#holidayStatus').prop('checked', true);
    resetHolidayScopeSelects();
    updateHolidayModeHint();
    new bootstrap.Modal(document.getElementById('holidayModal')).show();
}
function saveHoliday() {
    const nameTh = $('#holidayNameTh').val().trim();
    const nameEn = $('#holidayNameEn').val().trim();
    const date = $('#holidayDate').val();
    if (!nameTh || !nameEn || !date) { showWarning(langData['required_star_message'] || 'Please fill all fields marked with *'); return; }
    const mode = $('#holidayMode').val() || 'include';
    const assignments = [];
    HOLIDAY_SCOPE_TYPES.forEach(type => {
        ($(holidayScopeSelector(type)).val() || []).forEach(id => assignments.push({ scope_type: type, scope_id: parseInt(id) }));
    });
    if (mode === 'include' && assignments.length === 0) {
        showWarning(langData['include_requires_scope'] || 'Include mode requires at least one scope selection.');
        return;
    }
    const payload = {
        id: $('#holidayId').val() || null,
        name_th: nameTh, name_en: nameEn, holiday_date: date,
        is_recurring: $('#holidayRecurring').val() === '1' ? 1 : 0,
        assignment_mode: mode,
        remark: $('#holidayRemark').val().trim(),
        status: $('#holidayStatus').is(':checked') ? 'active' : 'inactive',
        assignments
    };
    $.ajax({
        url: `${BASE_URL}/api/holiday.save`, method: 'POST', contentType: 'application/json', data: JSON.stringify(payload), dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('holidayModal')).hide();
                dtHoliday.ajax.reload(null, false);
            } else { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
}

/* ==================== WORK LOCATION ==================== */
let dtWorkLocation;
function renderWorkLocation() {
    if ($.fn.DataTable.isDataTable('#tb_work_location')) { $('#tb_work_location').DataTable().ajax.reload(null, false); return; }
    dtWorkLocation = $('#tb_work_location').DataTable({
        ajax: { url: `${BASE_URL}/api/work-location.list`, dataSrc: 'data' },
        columns: [
            { data: null, render: (d, t, row) => `<div class="row-name">${escapeHtmlSr(currentLang === 'th' ? row.location_name_th : row.location_name_en)}</div>` },
            { data: 'location_code', render: d => `<span class="row-code">${escapeHtmlSr(d)}</span>` },
            { data: 'address', render: d => `<span class="text-faint">${d ? escapeHtmlSr(d) : '-'}</span>` },
            { data: 'status', className: 'text-center', render: (d, t, row) => statusSwitch(d === 'active', `toggleWorkLocationStatus(${row.id})`) },
            { data: null, orderable: false, className: 'text-end', render: (d, t, row) => actionBtns(`openWorkLocationModal(${row.id})`, `askDelete('location', ${row.id}, '${escapeHtmlSr(currentLang === 'th' ? row.location_name_th : row.location_name_en)}')`) }
        ],
        ordering: false, lengthChange: false, pageLength: 10,
        language: { ...getTableLang(), emptyTable: langData['no_work_locations_yet'] || 'No work locations have been added yet.' },
        initComplete: addButtonInitComplete('btn-add-location', 'fa-solid fa-plus', 'add_work_location', 'Add Location', 'openWorkLocationModal()')
    });
}
function toggleWorkLocationStatus(id) {
    $.ajax({
        url: `${BASE_URL}/api/work-location.toggle-status`, method: 'POST', data: { id }, dataType: 'json',
        success: function (res) {
            if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
            dtWorkLocation.ajax.reload(null, false);
        }
    });
}
function openWorkLocationModal(id) {
    $('#workLocationModalTitle').html(`<i class="fa-solid fa-location-dot"></i> <span data-i18n="work_location">${langData['work_location'] || 'Work Location'}</span>`);
    if (id) {
        $.ajax({
            url: `${BASE_URL}/api/work-location.get`, method: 'GET', data: { id }, dataType: 'json',
            success: function (res) {
                if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
                const l = res.data;
                $('#workLocationId').val(l.id);
                $('#workLocationNameTh').val(l.location_name_th);
                $('#workLocationNameEn').val(l.location_name_en);
                $('#workLocationCode').val(l.location_code);
                $('#workLocationAddress').val(l.address || '');
                $('#workLocationStatus').prop('checked', l.status === 'active');
                new bootstrap.Modal(document.getElementById('workLocationModal')).show();
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
        });
        return;
    }
    $('#workLocationId').val('');
    $('#workLocationNameTh').val(''); $('#workLocationNameEn').val(''); $('#workLocationCode').val('');
    $('#workLocationAddress').val('');
    $('#workLocationStatus').prop('checked', true);
    new bootstrap.Modal(document.getElementById('workLocationModal')).show();
}
function saveWorkLocation() {
    const nameTh = $('#workLocationNameTh').val().trim();
    const code = $('#workLocationCode').val().trim();
    if (!nameTh || !code) { showWarning(langData['required_star_message'] || 'Please fill all fields marked with *'); return; }
    const payload = {
        id: $('#workLocationId').val() || null,
        location_name_th: nameTh, location_name_en: $('#workLocationNameEn').val().trim(),
        location_code: code, address: $('#workLocationAddress').val().trim(),
        status: $('#workLocationStatus').is(':checked') ? 'active' : 'inactive'
    };
    $.ajax({
        url: `${BASE_URL}/api/work-location.save`, method: 'POST', contentType: 'application/json', data: JSON.stringify(payload), dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('workLocationModal')).hide();
                dtWorkLocation.ajax.reload(null, false);
            } else { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
}

/* ==================== LEAVE TYPE ==================== */
let dtLeave;
function leaveQuotaUnitLabel(unit) {
    if (unit === 'hour') return langData['unit_type_hour'] || 'Hour(s)';
    if (unit === 'half_day') return langData['unit_type_half_day'] || 'Half-day(s)';
    return langData['unit_type_day'] || 'Day(s)';
}
function renderLeave() {
    if ($.fn.DataTable.isDataTable('#tb_leave')) { $('#tb_leave').DataTable().ajax.reload(null, false); return; }
    dtLeave = $('#tb_leave').DataTable({
        ajax: { url: `${BASE_URL}/api/leave-type.list`, dataSrc: 'data' },
        columns: [
            { data: null, render: (d, t, row) => `<div class="row-name">${escapeHtmlSr(currentLang === 'th' ? row.name_th : row.name_en)}</div>` },
            { data: 'code', render: d => `<span class="row-code">${escapeHtmlSr(d)}</span>` },
            { data: null, render: (d, t, row) => `<span class="text-faint">${escapeHtmlSr(currentLang === 'th' ? row.category_name_th : row.category_name_en)}</span>` },
            { data: null, render: (d, t, row) => `<span class="text-faint">${parseFloat(row.quota_amount)} ${leaveQuotaUnitLabel(row.unit_type)}</span>` },
            { data: null, render: (d, t, row) => parseInt(row.is_paid) === 1 ? `<span class="badge-soft badge-paid">${langData['leave_pay_paid'] || 'Paid'}</span>` : `<span class="badge-soft badge-unpaid">${langData['leave_pay_unpaid'] || 'Unpaid'}</span>` },
            { data: null, render: (d, t, row) => parseInt(row.allow_carry_over) === 1 ? `<span class="text-faint"><i class="fa-solid fa-check text-success me-1"></i>${langData['allowed'] || 'Allowed'}</span>` : `<span class="text-faint">-</span>` },
            { data: 'status', className: 'text-center', render: (d, t, row) => statusSwitch(d === 'active', `toggleLeaveStatus(${row.id})`) },
            { data: null, orderable: false, className: 'text-end', render: (d, t, row) => actionBtns(`openLeaveModal(${row.id})`, `askDelete('leave', ${row.id}, '${escapeHtmlSr(currentLang === 'th' ? row.name_th : row.name_en)}')`) }
        ],
        ordering: false, lengthChange: false, pageLength: 10,
        language: { ...getTableLang(), emptyTable: langData['no_leave_types_found'] || 'No leave types found.' },
        initComplete: addButtonInitComplete('btn-add-leave', 'fa-solid fa-plus', 'add_leave_type', 'Add Leave Type', 'openLeaveModal()')
    });
}
function toggleLeaveStatus(id) {
    $.ajax({
        url: `${BASE_URL}/api/leave-type.toggle-status`, method: 'POST', data: { id }, dataType: 'json',
        success: function (res) {
            if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
            dtLeave.ajax.reload(null, false);
        }
    });
}
function initLeaveModalSelects() {
    initSelect2('#leaveCategory', { mode: 'ajax' });
    initSelect2('#leaveQuotaType', { mode: 'static' });
    initSelect2('#leaveUnitType', { mode: 'static' });
    initSelect2('#leavePayType', { mode: 'static' });
    initSelect2('#leaveGenderRestriction', { mode: 'static' });
    initSelect2('#leaveApplicableStatuses', { mode: 'static' });
}
function openLeaveModal(id) {
    $('#leaveModalTitle').html(`<i class="fa-regular fa-calendar-check"></i> <span data-i18n="leave_type">${langData['leave_type'] || 'Leave Type'}</span>`);
    initLeaveModalSelects();
    if (id) {
        $.ajax({
            url: `${BASE_URL}/api/leave-type.get`, method: 'GET', data: { id }, dataType: 'json',
            success: function (res) {
                if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
                const l = res.data;
                $('#leaveId').val(l.id);
                $('#leaveNameTh').val(l.name_th); $('#leaveNameEn').val(l.name_en);
                $('#leaveCode').val(l.code);
                const $cat = $('#leaveCategory');
                $cat.empty().append(new Option(currentLang === 'th' ? l.category_name_th : l.category_name_en, l.category_id, true, true)).trigger('change.select2');
                $('#leaveQuotaType').val(l.quota_type).trigger('change.select2');
                $('#leaveQuota').val(l.quota_amount);
                $('#leaveUnitType').val(l.unit_type).trigger('change.select2');
                $('#leavePayType').val(String(l.is_paid)).trigger('change.select2');
                $('#leaveGenderRestriction').val(l.gender_restriction).trigger('change.select2');
                $('#leaveMinServiceDays').val(l.min_service_days !== null ? l.min_service_days : '');
                $('#leaveAdvanceNoticeDays').val(l.advance_notice_days !== null ? l.advance_notice_days : '');
                $('#leaveMaxConsecutiveDays').val(l.max_consecutive_days !== null ? l.max_consecutive_days : '');
                const statuses = l.applicable_employment_statuses ? l.applicable_employment_statuses.split(',') : [];
                $('#leaveApplicableStatuses').val(statuses).trigger('change.select2');
                $('#leaveRequiresDocument').prop('checked', parseInt(l.requires_document) === 1);
                $('#leaveCountWorkingDaysOnly').prop('checked', parseInt(l.is_continuous) !== 1);
                $('#leaveCarryOver').prop('checked', parseInt(l.allow_carry_over) === 1);
                $('#leaveStatus').prop('checked', l.status === 'active');
                new bootstrap.Modal(document.getElementById('leaveModal')).show();
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
        });
        return;
    }
    $('#leaveId').val('');
    $('#leaveNameTh').val(''); $('#leaveNameEn').val(''); $('#leaveCode').val('');
    $('#leaveCategory').empty().trigger('change.select2');
    $('#leaveQuotaType').val('fixed').trigger('change.select2');
    $('#leaveQuota').val(0);
    $('#leaveUnitType').val('day').trigger('change.select2');
    $('#leavePayType').val('1').trigger('change.select2');
    $('#leaveGenderRestriction').val('all').trigger('change.select2');
    $('#leaveMinServiceDays').val(''); $('#leaveAdvanceNoticeDays').val(''); $('#leaveMaxConsecutiveDays').val('');
    $('#leaveApplicableStatuses').val(null).trigger('change.select2');
    $('#leaveRequiresDocument').prop('checked', false);
    $('#leaveCountWorkingDaysOnly').prop('checked', true);
    $('#leaveCarryOver').prop('checked', false);
    $('#leaveStatus').prop('checked', true);
    new bootstrap.Modal(document.getElementById('leaveModal')).show();
}
function saveLeave() {
    const nameTh = $('#leaveNameTh').val().trim();
    const nameEn = $('#leaveNameEn').val().trim();
    const code = $('#leaveCode').val().trim();
    const categoryId = $('#leaveCategory').val();
    if (!nameTh || !nameEn || !code || !categoryId) { showWarning(langData['required_star_message'] || 'Please fill all fields marked with *'); return; }
    const payload = {
        id: $('#leaveId').val() || null,
        name_th: nameTh, name_en: nameEn, code: code, category_id: parseInt(categoryId),
        quota_type: $('#leaveQuotaType').val(), quota_amount: parseFloat($('#leaveQuota').val()) || 0,
        unit_type: $('#leaveUnitType').val(),
        is_paid: $('#leavePayType').val() === '1' ? 1 : 0,
        gender_restriction: $('#leaveGenderRestriction').val() || 'all',
        min_service_days: $('#leaveMinServiceDays').val() !== '' ? parseInt($('#leaveMinServiceDays').val()) : null,
        advance_notice_days: $('#leaveAdvanceNoticeDays').val() !== '' ? parseInt($('#leaveAdvanceNoticeDays').val()) : null,
        max_consecutive_days: $('#leaveMaxConsecutiveDays').val() !== '' ? parseFloat($('#leaveMaxConsecutiveDays').val()) : null,
        applicable_employment_statuses: $('#leaveApplicableStatuses').val() || [],
        requires_document: $('#leaveRequiresDocument').is(':checked') ? 1 : 0,
        is_continuous: $('#leaveCountWorkingDaysOnly').is(':checked') ? 0 : 1,
        allow_carry_over: $('#leaveCarryOver').is(':checked') ? 1 : 0,
        status: $('#leaveStatus').is(':checked') ? 'active' : 'inactive'
    };
    $.ajax({
        url: `${BASE_URL}/api/leave-type.save`, method: 'POST', contentType: 'application/json', data: JSON.stringify(payload), dataType: 'json',
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

/* ==================== OT RATE ==================== */
let dtOt;
function renderOt() {
    if ($.fn.DataTable.isDataTable('#tb_ot')) { $('#tb_ot').DataTable().ajax.reload(null, false); return; }
    dtOt = $('#tb_ot').DataTable({
        ajax: { url: `${BASE_URL}/api/ot-rate.list`, dataSrc: 'data' },
        columns: [
            { data: null, render: (d, t, row) => `<div class="row-name">${escapeHtmlSr(currentLang === 'th' ? row.ot_name_th : row.ot_name_en)}</div>` },
            { data: null, render: (d, t, row) => `<span class="badge-soft badge-weekday">${escapeHtmlSr(currentLang === 'th' ? row.scope_name_th : row.scope_name_en)}</span>` },
            { data: null, render: (d, t, row) => `<span class="row-code">${parseFloat(row.multiplier_rate).toFixed(1)}x</span>` },
            { data: null, render: (d, t, row) => `<span class="text-faint">${row.calculation_base === 'daily' ? (langData['ot_base_daily'] || 'Daily') : (langData['ot_base_hourly'] || 'Hourly')}</span>` },
            { data: 'status', className: 'text-center', render: (d, t, row) => statusSwitch(d === 'active', `toggleOtStatus(${row.id})`) },
            { data: null, orderable: false, className: 'text-end', render: (d, t, row) => actionBtns(`openOtModal(${row.id})`, `askDelete('ot', ${row.id}, '${escapeHtmlSr(currentLang === 'th' ? row.ot_name_th : row.ot_name_en)}')`) }
        ],
        ordering: false, lengthChange: false, pageLength: 10,
        language: { ...getTableLang(), emptyTable: langData['no_ot_rates_yet'] || 'No OT rates have been added yet.' },
        initComplete: addButtonInitComplete('btn-add-ot', 'fa-solid fa-plus', 'add_ot_rate', 'Add OT Rate', 'openOtModal()')
    });
}
function toggleOtStatus(id) {
    $.ajax({
        url: `${BASE_URL}/api/ot-rate.toggle-status`, method: 'POST', data: { id }, dataType: 'json',
        success: function (res) {
            if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
            dtOt.ajax.reload(null, false);
        }
    });
}
function openOtModal(id) {
    $('#otModalTitle').html(`<i class="fa-solid fa-coins"></i> <span data-i18n="ot_rate">${langData['ot_rate'] || 'OT Rate'}</span>`);
    initSelect2('#otScope', { mode: 'ajax' });
    initSelect2('#otBase', { mode: 'static' });
    if (id) {
        $.ajax({
            url: `${BASE_URL}/api/ot-rate.get`, method: 'GET', data: { id }, dataType: 'json',
            success: function (res) {
                if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
                const o = res.data;
                $('#otId').val(o.id);
                $('#otNameTh').val(o.ot_name_th);
                $('#otNameEn').val(o.ot_name_en);
                const $scope = $('#otScope');
                $scope.empty().append(new Option(currentLang === 'th' ? o.scope_name_th : o.scope_name_en, o.ot_scope_id, true, true)).trigger('change.select2');
                $('#otMultiplier').val(o.multiplier_rate);
                $('#otBase').val(o.calculation_base).trigger('change.select2');
                $('#otStatus').prop('checked', o.status === 'active');
                new bootstrap.Modal(document.getElementById('otModal')).show();
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
        });
        return;
    }
    $('#otId').val(''); $('#otNameTh').val(''); $('#otNameEn').val('');
    $('#otScope').empty().trigger('change.select2');
    $('#otMultiplier').val(1.5);
    $('#otBase').val('hourly').trigger('change.select2');
    $('#otStatus').prop('checked', true);
    new bootstrap.Modal(document.getElementById('otModal')).show();
}
function saveOt() {
    const nameTh = $('#otNameTh').val().trim();
    const scopeId = $('#otScope').val();
    const multiplier = parseFloat($('#otMultiplier').val());
    if (!nameTh || !scopeId || !multiplier || multiplier <= 0) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const payload = {
        id: $('#otId').val() || null,
        ot_name_th: nameTh, ot_name_en: $('#otNameEn').val().trim(),
        ot_scope_id: parseInt(scopeId), multiplier_rate: multiplier,
        calculation_base: $('#otBase').val() || 'hourly',
        status: $('#otStatus').is(':checked') ? 'active' : 'inactive'
    };
    $.ajax({
        url: `${BASE_URL}/api/ot-rate.save`, method: 'POST', contentType: 'application/json', data: JSON.stringify(payload), dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('otModal')).hide();
                dtOt.ajax.reload(null, false);
            } else { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
}

$(function () {
    initHolidayScopeSelects();
    renderShift();
    renderHoliday();
    renderLeave();
    renderOt();
    renderWorkLocation();
});
