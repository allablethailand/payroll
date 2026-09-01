/**
 * Setup & Rules page. Shift, Holiday, Work Location, Leave Type, and OT Rate are all wired to real
 * backends (SetupRulesController). OT Rate briefly moved to Payroll Configuration and back the same
 * day (2026-08-21, "ย้ายตัวคูณ OT ไปไว้ที่เดิมครับ") -- stays here now.
 *
 * All DataTables here rely on DataTables' own built-in search box (default `dom`, no override)
 * with the "Add" button injected into `.dt-search` via `initComplete`, matching the convention
 * used by Approval Workflow / Payroll Cycle / Employee list -- NOT a hand-written search box.
 */
function statusSwitch(checked, onchange) {
    return `<div class="form-check form-switch d-flex justify-content-center m-0">
        <input class="form-check-input" type="checkbox" ${checked ? 'checked' : ''} onchange="${onchange}">
    </div>`;
}
// 2026-08-31, explicit request: Assign Employees modal -- `extraBtns` is an optional 3rd param
// (empty string default) so every OTHER call site of this shared helper (Holiday/Leave/OT, not in
// this batch's scope) stays byte-identical; only Shift/Work Location's own call sites pass it.
function actionBtns(editFn, delFn, extraBtns) {
    return `
    <div class="btn-group border rounded-3 bg-white">
        <button class="btn btn-link text-warning" onclick="${editFn}"><i class="fa-solid fa-pen-to-square"></i></button>
        ${extraBtns || ''}
        <button class="btn btn-link py-1 text-danger border-start" onclick="${delFn}"><i class="fa-solid fa-trash-can"></i></button>
    </div>`;
}
function structureAssignExtraBtns(type, id, label) {
    return `
        <button type="button" class="btn btn-link py-1 text-primary border-start btn-structure-assign" data-type="${type}" data-id="${id}" data-label="${escapeAttrSr(label)}" data-i18n-title="assign_employees"><i class="fa-solid fa-user-plus"></i></button>
        <button type="button" class="btn btn-link py-1 text-secondary border-start btn-structure-view-assigned" data-type="${type}" data-id="${id}" data-label="${escapeAttrSr(label)}" data-i18n-title="view_assigned_employees"><i class="fa-solid fa-users"></i></button>
    `;
}
function escapeAttrSr(str) {
    return escapeHtmlSr(str).replace(/"/g, '&quot;');
}
function escapeHtmlSr(str) {
    return $('<div>').text(str || '').html().replace(/"/g, '&quot;');
}
function fmtDate(d) {
    const dt = new Date(d + "T00:00:00");
    return dt.toLocaleDateString(currentLang === 'th' ? 'th-TH' : 'en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}
// 2026-08-29, real bug found and fixed (explicit report: "เวลาที่ Save ลงใน Database เป็น UTC การ
// แสดงผลให้แปลงเป็น timezone ปัจจุบันของผู้ใช้") -- the Shift table's "Last Updated" column feeds a
// real UTC timestamp (updated_at/created_at) straight into fmtDate() via `.slice(0, 10)`, extracting
// the raw date portion BEFORE any timezone conversion -- can show the wrong calendar day for a
// viewer far from UTC (same bug class already fixed in payroll/detail.js's own
// toLocalDateOnlyRd()). Converts via formatDisplayDateTime() (UTC-aware, app.js) first, then
// re-extracts just the date in the YYYY-MM-DD shape fmtDate() itself expects, so fmtDate()'s own
// genuinely-date-only callers (holiday_date below) are completely unaffected.
function localDateOnlyFromUtcSr(value) {
    if (!value) return '';
    if (typeof formatDisplayDateTime !== 'function') return String(value).slice(0, 10);
    const [dd, mm, yyyy] = formatDisplayDateTime(value).split(' ')[0].split('/');
    return `${yyyy}-${mm}-${dd}`;
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
// 2026-08-30, real pattern violation found and fixed: this used to open a Bootstrap modal
// (#deleteModal) for delete confirmation across all 5 tabs -- CLAUDE.md's UI convention requires
// SweetAlert2 for every alert/confirm, no Bootstrap modal/native confirm(). Replaced with a direct
// showConfirm() call, same pattern already established elsewhere (e.g.
// payslip-template.js's .pst-delete-lang-item handler) -- no modal markup needed at all, so
// #deleteModal/#deleteTargetName were removed from setup-rules/index.php entirely.
const SETUP_RULES_DELETE_ENDPOINTS = {
    shift: '/api/shift.delete', holiday: '/api/holiday.delete', location: '/api/work-location.delete',
    leave: '/api/leave-type.delete', ot: '/api/ot-rate.delete',
};
function askDelete(type, id, name) {
    const endpoint = SETUP_RULES_DELETE_ENDPOINTS[type];
    if (!endpoint) return;
    const question = langData['delete_confirm_question'] || 'Delete';
    const note = langData['delete_irreversible_note'] || 'This action cannot be undone.';
    showConfirm(langData['confirm_delete_title'] || 'Confirm Delete', `${question} "${name}"? ${note}`, function () {
        ajaxDelete(endpoint, SETUP_RULES_DELETE_TABLES[type](), id);
    });
}
function ajaxDelete(url, table, id) {
    $.ajax({
        url: `${BASE_URL}${url}`, method: 'POST', data: { id }, dataType: 'json',
        success: function (res) {
            if (res.status) { showSuccess(res.message || langData['delete_success'] || 'Deleted successfully.'); table.ajax.reload(null, false); }
            else { showWarning(res.message || langData['delete_failed'] || 'Failed to delete.'); }
        },
        error: function () { showWarning(langData['delete_failed'] || 'Failed to delete.'); }
    });
}
// Each table variable (dtShift/dtHoliday/etc.) is only assigned once its own tab has actually
// initialized -- deferred behind a function (not a plain object literal evaluated at file-parse
// time, before any of them exist yet) so askDelete() always reads the table var's CURRENT value.
const SETUP_RULES_DELETE_TABLES = {
    shift: () => dtShift, holiday: () => dtHoliday, location: () => dtWorkLocation,
    leave: () => dtLeave, ot: () => dtOt,
};

/* ==================== SHIFT ==================== */
let dtShift;
// Day-of-week keys match the shifts.works_* DB columns / payload field names verbatim.
const SHIFT_WORK_DAY_KEYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
const SHIFT_WORK_DAY_SHORT_KEYS = { monday: 'day_mon_short', tuesday: 'day_tue_short', wednesday: 'day_wed_short', thursday: 'day_thu_short', friday: 'day_fri_short', saturday: 'day_sat_short', sunday: 'day_sun_short' };
function shiftWorkDaysSummary(row) {
    const active = SHIFT_WORK_DAY_KEYS.filter(k => !!row['works_' + k]);
    if (active.length === 7) { return langData['every_day'] || 'Every day'; }
    if (active.length === 0) { return '<span class="text-faint">-</span>'; }
    return active.map(k => langData[SHIFT_WORK_DAY_SHORT_KEYS[k]] || k.slice(0, 3)).join(', ');
}
function setShiftWorkDays(row) {
    SHIFT_WORK_DAY_KEYS.forEach(k => {
        $(`#shiftWorkDaysToggle button[data-day="${k}"]`).toggleClass('active', row ? !!row['works_' + k] : (k !== 'saturday' && k !== 'sunday'));
    });
}
function getShiftWorkDaysPayload() {
    const payload = {};
    SHIFT_WORK_DAY_KEYS.forEach(k => { payload['works_' + k] = $(`#shiftWorkDaysToggle button[data-day="${k}"]`).hasClass('active') ? 1 : 0; });
    return payload;
}
function renderShift() {
    if ($.fn.DataTable.isDataTable('#tb_shift')) { $('#tb_shift').DataTable().ajax.reload(null, false); return; }
    dtShift = $('#tb_shift').DataTable({
        responsive: true,
        ajax: { url: `${BASE_URL}/api/shift.list`, dataSrc: 'data' },
        columns: [
            { data: null, render: (d, t, row) => `<div class="row-name">${escapeHtmlSr(currentLang === 'th' ? row.shift_name_th : row.shift_name_en)}</div>` },
            { data: 'shift_code', render: d => `<span class="row-code">${escapeHtmlSr(d)}</span>` },
            { data: null, render: (d, t, row) => `<span class="text-faint"><i class="fa-regular fa-clock me-1"></i>${(row.start_time || '').slice(0, 5)} - ${(row.end_time || '').slice(0, 5)}</span>` },
            { data: null, render: (d, t, row) => `<span class="text-faint">${shiftWorkDaysSummary(row)}</span>` },
            { data: null, render: (d, t, row) => `<span class="text-faint">${row.location_name_th ? escapeHtmlSr(currentLang === 'th' ? row.location_name_th : row.location_name_en) : '-'}</span>` },
            { data: null, render: (d, t, row) => `<span class="text-faint">${row.updated_at ? fmtDate(localDateOnlyFromUtcSr(row.updated_at)) : fmtDate(localDateOnlyFromUtcSr(row.created_at))}</span>` },
            { data: 'status', className: 'text-center', render: (d, t, row) => statusSwitch(d === 'active', `toggleShiftStatus(${row.id})`) },
            // 2026-08-28: className:'all' keeps this last actions column from collapsing into the
            // Responsive expand row.
            // 2026-08-30, explicit request: "ตัดการ Assign ออกไปเลย เพราะสามารถเพิ่มได้ในฝั่งพนักงานอยู่แล้ว" --
            // the per-shift bulk Assign button/modal is gone (an employee's own Shift dropdown on
            // Employee Detail's Employment tab already sets the same employees.shift_id column, so
            // this was a redundant second path). Back to the plain shared actionBtns() every other
            // table in this file already uses -- SetupRulesModel::shiftAssignEmployees()/the
            // api/shift.assign-employees route are left in place, unused by any UI now, in case an
            // API consumer wants bulk-assign later.
            { data: null, orderable: false, className: 'text-end all', render: (d, t, row) => actionBtns(`openShiftModal(${row.id})`, `askDelete('shift', ${row.id}, '${escapeHtmlSr(currentLang === 'th' ? row.shift_name_th : row.shift_name_en)}')`, structureAssignExtraBtns('shift', row.id, currentLang === 'th' ? row.shift_name_th : row.shift_name_en)) }
        ],
        ordering: false, lengthChange: false, pageLength: 10,
        language: { ...getTableLang(), emptyTable: langData['no_shifts_yet'] || 'No shifts have been added yet.' },
        initComplete: function () {
            addButtonInitComplete('btn-add-shift', 'fa-solid fa-plus', 'add_shift', 'Shift', 'openShiftModal()').call(this);
            // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
            // rollout, client mode. Excludes the multi-value work-days summary (3, composite), the
            // interactive status SWITCH (6, not a display value), and actions (7).
            initExcelColumnFilters(this.api(), {
                mode: 'client',
                columns: [
                    { index: 0, key: 'name' },
                    { index: 1, key: 'code' },
                    { index: 2, key: 'time_range' },
                    { index: 4, key: 'location' },
                    { index: 5, key: 'updated_at' },
                ]
            });
        }
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
                setShiftWorkDays(s);
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
    setShiftWorkDays(null);
    new bootstrap.Modal(document.getElementById('shiftModal')).show();
}
function saveShift() {
    const name = $('#shiftName').val().trim(), code = $('#shiftCode').val().trim();
    if (!name || !code) { showWarning(langData['required_star_message'] || 'Please fill all fields marked with *'); return; }
    const payload = Object.assign({
        id: $('#shiftId').val() || null,
        shift_name_th: name, shift_name_en: name, shift_code: code,
        description: $('#shiftDesc').val().trim(),
        start_time: $('#shiftStart').val(), end_time: $('#shiftEnd').val(),
        break_minutes: parseInt($('#shiftBreak').val()) || 0,
        work_location_id: $('#shiftWorkLocation').val() || null,
        status: $('#shiftStatus').is(':checked') ? 'active' : 'inactive'
    }, getShiftWorkDaysPayload());
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
        responsive: true,
        ajax: { url: `${BASE_URL}/api/holiday.list`, dataSrc: 'data' },
        columns: [
            { data: null, render: (d, t, row) => `<div class="row-name">${escapeHtmlSr(currentLang === 'th' ? row.name_th : row.name_en)}</div>` },
            { data: null, render: (d, t, row) => `<span class="text-faint"><i class="fa-regular fa-calendar me-1"></i>${fmtDate(row.holiday_date)}</span>` },
            { data: null, render: (d, t, row) => parseInt(row.is_recurring) === 1 ? `<span class="badge-soft badge-paid">${langData['recurring_every_year'] || 'Recurring'}</span>` : `<span class="badge-soft badge-unpaid">${langData['one_time_only'] || 'One-time'}</span>` },
            { data: null, render: (d, t, row) => holidayScopeSummary(row) },
            { data: 'status', className: 'text-center', render: (d, t, row) => statusSwitch(d === 'active', `toggleHolidayStatus(${row.id})`) },
            // 2026-08-28: className:'all' keeps this last actions column from collapsing into the
            // Responsive expand row.
            { data: null, orderable: false, className: 'text-end all', render: (d, t, row) => actionBtns(`openHolidayModal(${row.id})`, `askDelete('holiday', ${row.id}, '${escapeHtmlSr(currentLang === 'th' ? row.name_th : row.name_en)}')`) }
        ],
        ordering: false, lengthChange: false, pageLength: 10,
        language: { ...getTableLang(), emptyTable: langData['no_holidays_found'] || 'No holidays found.' },
        initComplete: function () {
            addButtonInitComplete('btn-add-holiday', 'fa-solid fa-plus', 'add_holiday', 'Holiday', 'openHolidayModal()').call(this);
            // 2026-08-28, explicit request: "เพิ่มให้ Sync ข้อมูลวันหยุดตามประกาศจาก API ที่มี" -- see
            // public/js/setup/holiday-sync.js for the picker modal this opens, and
            // HolidaySyncModel's own docblock for the full design.
            const $wrapper = $(this.api().table().container());
            const $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find('#btnOpenHolidaySync').length === 0) {
                $searchDiv.append(`
                    <button type="button" class="btn btn-outline-secondary ms-1" id="btnOpenHolidaySync">
                        <i class="fa-brands fa-google me-1"></i><span data-i18n="holiday_sync_button">Sync from Google Calendar</span>
                    </button>
                `);
            }
            if ($searchDiv.find('#btnOpenHolidaySyncLog').length === 0) {
                $searchDiv.append(`
                    <button type="button" class="btn btn-outline-secondary ms-1" id="btnOpenHolidaySyncLog">
                        <i class="fa-solid fa-clock-rotate-left me-1"></i><span data-i18n="holiday_sync_log_button">Sync Log</span>
                    </button>
                `);
            }
            if (typeof updateText === 'function') updateText($searchDiv[0]);
            // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
            // rollout, client mode. Excludes the multi-value scope summary (3, composite), the
            // interactive status SWITCH (4), and actions (5).
            initExcelColumnFilters(this.api(), {
                mode: 'client',
                columns: [
                    { index: 0, key: 'name' },
                    { index: 1, key: 'holiday_date' },
                    { index: 2, key: 'recurring' },
                ]
            });
        }
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
        responsive: true,
        ajax: { url: `${BASE_URL}/api/work-location.list`, dataSrc: 'data' },
        columns: [
            { data: null, render: (d, t, row) => `<div class="row-name">${escapeHtmlSr(currentLang === 'th' ? row.location_name_th : row.location_name_en)}</div>` },
            { data: 'location_code', render: d => `<span class="row-code">${escapeHtmlSr(d)}</span>` },
            { data: 'address', render: d => `<span class="text-faint">${d ? escapeHtmlSr(d) : '-'}</span>` },
            { data: 'status', className: 'text-center', render: (d, t, row) => statusSwitch(d === 'active', `toggleWorkLocationStatus(${row.id})`) },
            // 2026-08-28: className:'all' keeps this last actions column from collapsing into the
            // Responsive expand row.
            { data: null, orderable: false, className: 'text-end all', render: (d, t, row) => actionBtns(`openWorkLocationModal(${row.id})`, `askDelete('location', ${row.id}, '${escapeHtmlSr(currentLang === 'th' ? row.location_name_th : row.location_name_en)}')`, structureAssignExtraBtns('work_location', row.id, currentLang === 'th' ? row.location_name_th : row.location_name_en)) }
        ],
        ordering: false, lengthChange: false, pageLength: 10,
        language: { ...getTableLang(), emptyTable: langData['no_work_locations_yet'] || 'No work locations have been added yet.' },
        initComplete: function () {
            addButtonInitComplete('btn-add-location', 'fa-solid fa-plus', 'add_work_location', 'Location', 'openWorkLocationModal()').call(this);
            // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
            // rollout, client mode. Excludes the interactive status SWITCH (3) and actions (4).
            initExcelColumnFilters(this.api(), {
                mode: 'client',
                columns: [
                    { index: 0, key: 'name' },
                    { index: 1, key: 'code' },
                    { index: 2, key: 'address' },
                ]
            });
        }
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
        responsive: true,
        ajax: { url: `${BASE_URL}/api/leave-type.list`, dataSrc: 'data' },
        columns: [
            { data: null, render: (d, t, row) => `<div class="row-name">${escapeHtmlSr(currentLang === 'th' ? row.name_th : row.name_en)}</div>` },
            { data: 'code', render: d => `<span class="row-code">${escapeHtmlSr(d)}</span>` },
            { data: null, render: (d, t, row) => `<span class="text-faint">${escapeHtmlSr(currentLang === 'th' ? row.category_name_th : row.category_name_en)}</span>` },
            { data: null, className: 'text-end', render: (d, t, row) => `<span class="text-faint">${parseFloat(row.quota_amount)} ${leaveQuotaUnitLabel(row.unit_type)}</span>` },
            { data: null, render: (d, t, row) => parseInt(row.is_paid) === 1 ? `<span class="badge-soft badge-paid">${langData['leave_pay_paid'] || 'Paid'}</span>` : `<span class="badge-soft badge-unpaid">${langData['leave_pay_unpaid'] || 'Unpaid'}</span>` },
            { data: null, render: (d, t, row) => parseInt(row.allow_carry_over) === 1 ? `<span class="text-faint"><i class="fa-solid fa-check text-success me-1"></i>${langData['allowed'] || 'Allowed'}</span>` : `<span class="text-faint">-</span>` },
            { data: 'status', className: 'text-center', render: (d, t, row) => statusSwitch(d === 'active', `toggleLeaveStatus(${row.id})`) },
            // 2026-08-28: className:'all' keeps this last actions column from collapsing into the
            // Responsive expand row.
            { data: null, orderable: false, className: 'text-end all', render: (d, t, row) => actionBtns(`openLeaveModal(${row.id})`, `askDelete('leave', ${row.id}, '${escapeHtmlSr(currentLang === 'th' ? row.name_th : row.name_en)}')`) }
        ],
        ordering: false, lengthChange: false, pageLength: 10,
        language: { ...getTableLang(), emptyTable: langData['no_leave_types_found'] || 'No leave types found.' },
        initComplete: function () {
            addButtonInitComplete('btn-add-leave', 'fa-solid fa-plus', 'add_leave_type', 'Leave Type', 'openLeaveModal()').call(this);
            // "Apply Default" (2026-08-28) -- seeds the 10 starter leave types (see
            // SetupRulesModel::LEAVE_TYPE_DEFAULTS) via api/leave-type.apply-defaults. Idempotent
            // (skips any code that already exists for this company), so the button stays useful
            // indefinitely, not just on a first-time empty table. Shares generic apply_default_*
            // i18n keys intended for reuse on other data-management tabs later.
            const $wrapper = $(this.api().table().container());
            const $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find('#btnApplyLeaveTypeDefaults').length === 0) {
                $searchDiv.append(`
                    <button type="button" class="btn btn-outline-secondary ms-1" id="btnApplyLeaveTypeDefaults">
                        <i class="fa-solid fa-wand-magic-sparkles me-1"></i><span data-i18n="apply_default_button">Apply Default</span>
                    </button>
                `);
            }
            if (typeof updateText === 'function') updateText($searchDiv[0]);
            // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
            // rollout, client mode. Excludes the interactive status SWITCH (6) and actions (7).
            initExcelColumnFilters(this.api(), {
                mode: 'client',
                columns: [
                    { index: 0, key: 'name' },
                    { index: 1, key: 'code' },
                    { index: 2, key: 'category' },
                    { index: 3, key: 'quota' },
                    { index: 4, key: 'paid' },
                    { index: 5, key: 'carry_over' },
                ]
            });
        }
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
    $('#leaveTypeModalTitle').html(`<i class="fa-regular fa-calendar-check"></i> <span data-i18n="leave_type">${langData['leave_type'] || 'Leave Type'}</span>`);
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
                new bootstrap.Modal(document.getElementById('leaveTypeModal')).show();
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
    new bootstrap.Modal(document.getElementById('leaveTypeModal')).show();
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
                bootstrap.Modal.getInstance(document.getElementById('leaveTypeModal')).hide();
                dtLeave.ajax.reload(null, false);
            } else { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
}
$(document).on('click', '#btnApplyLeaveTypeDefaults', function () {
    showConfirm(
        langData['apply_default_confirm_title'] || 'Apply default data?',
        langData['apply_default_confirm_message'] || 'This will add any missing standard starter records. Existing records will not be changed.',
        function () {
            $.ajax({
                url: `${BASE_URL}/api/leave-type.apply-defaults`, method: 'POST', dataType: 'json',
                success: function (res) {
                    if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
                    const tpl = langData['apply_default_result'] || 'Added {created} new record(s). {skipped} already existed.';
                    showSuccess(tpl.replace('{created}', res.created).replace('{skipped}', res.skipped));
                    dtLeave.ajax.reload(null, false);
                },
                error: function () { showWarning(langData['save_failed'] || 'An error occurred.'); }
            });
        }
    );
});

/* ==================== OT RATE SET ====================
 * 2026-08-30, full replacement of the old flat one-row-per-scope `ot_rates` CRUD -- explicit request:
 * "ตอนกดบวกรายการ ให้ขึ้นมาเลยเป็นชุดของ OT Type แล้วมี form ในแต่ละ Type ให้ระบุ...1 ชุดข้อมูลมีทุก Type ให้
 * จัดการ แต่สามารถจัดการแยกกันได้แต่ละ type ในแถวเดียวกัน และให้เพิ่มการ Assign ให้ด้วย...ป้องกันการบันทึกซ้ำ...
 * บังคับไปเลยว่าต้องมี Default". Backed by OtRateSetModel (app/models/OtRateSetModel.php) -- see that
 * class's own docblock for the full architecture. `dtOt`/`askDelete('ot', ...)`/the
 * `api/ot-rate.delete`/`.toggle-status` endpoints keep their old names for continuity (delegate to
 * OtRateSetModel now, not SetupRulesModel).
 */
let dtOt;
let otScopeCache = null; // master_ot_scope_types options, fetched once and reused (id/code/name_th/name_en)
function otScopeOptionsPromise() {
    if (otScopeCache) { return $.Deferred().resolve(otScopeCache).promise(); }
    // 2026-08-31, real bug found and fixed (explicit report: "GET .../api/ot-rate.scope-options 404
    // (Not Found)") -- the route is registered POST-only (`$router->post('api/ot-rate.scope-options',
    // ...)`, same as every other select2-remote-style options endpoint in this app), but this used
    // $.get() (a GET request) instead of $.post().
    return $.post(`${BASE_URL}/api/ot-rate.scope-options`).then(function (res) {
        otScopeCache = (res && res.data && res.data.items) || [];
        return otScopeCache;
    });
}
function otItemBadge(item) {
    if (!item) { return `<span class="text-muted small">${langData['ot_rate_set_not_configured'] || 'Not set'}</span>`; }
    return item.calculation_method === 'flat_amount'
        ? `<span class="row-code">${parseFloat(item.flat_amount_rate || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}/${item.calculation_base === 'daily' ? (langData['ot_base_daily'] || 'Daily') : (langData['ot_base_hourly'] || 'Hourly')}</span>`
        : `<span class="row-code">${parseFloat(item.multiplier_rate).toFixed(1)}x</span>`;
}
function otItemByScopeCode(row, code) {
    return (row.items || []).find(i => i.scope_code === code) || null;
}
function otAssignSummary(row) {
    if (row.is_default) { return `<span class="badge-soft badge-weekday"><i class="fa-solid fa-star me-1"></i>${langData['ot_rate_set_unassigned'] || 'Unassigned'}</span>`; }
    const n = (row.assignments || []).length;
    if (n === 0) { return `<span class="text-muted small">-</span>`; }
    return `<span class="row-code">${n} ${langData['ot_rate_set_assignments_summary'] || 'assigned'}</span>`;
}
function renderOt() {
    if ($.fn.DataTable.isDataTable('#tb_ot')) { $('#tb_ot').DataTable().ajax.reload(null, false); return; }
    dtOt = $('#tb_ot').DataTable({
        responsive: true,
        ajax: { url: `${BASE_URL}/api/ot-rate.list`, dataSrc: 'data' },
        columns: [
            { data: null, render: (d, t, row) => `<div class="row-name">${escapeHtmlSr(currentLang === 'th' ? row.name_th : row.name_en)}</div>` },
            { data: null, className: 'text-end', render: (d, t, row) => otItemBadge(otItemByScopeCode(row, 'weekday')) },
            { data: null, className: 'text-end', render: (d, t, row) => otItemBadge(otItemByScopeCode(row, 'weekend')) },
            { data: null, className: 'text-end', render: (d, t, row) => otItemBadge(otItemByScopeCode(row, 'holiday')) },
            { data: null, render: (d, t, row) => otAssignSummary(row) },
            {
                data: 'is_default', className: 'text-center', render: (d, t, row) => d
                    ? `<i class="fa-solid fa-star text-warning" title="${langData['ot_rate_set_default_badge'] || 'Default'}"></i>`
                    : `<button type="button" class="btn btn-link p-0 text-muted" title="${langData['ot_rate_set_make_default'] || 'Make Default'}" onclick="askOtSetDefault(${row.id}, '${escapeHtmlSr(currentLang === 'th' ? row.name_th : row.name_en)}')"><i class="fa-regular fa-star"></i></button>`
            },
            { data: 'status', className: 'text-center', render: (d, t, row) => statusSwitch(d === 'active', `toggleOtStatus(${row.id})`) },
            // 2026-08-28: className:'all' keeps this last actions column from collapsing into the
            // Responsive expand row.
            { data: null, orderable: false, className: 'text-end all', render: (d, t, row) => actionBtns(`openOtModal(${row.id})`, `askDelete('ot', ${row.id}, '${escapeHtmlSr(currentLang === 'th' ? row.name_th : row.name_en)}')`) }
        ],
        ordering: false, lengthChange: false, pageLength: 10,
        language: { ...getTableLang(), emptyTable: langData['no_ot_rate_sets_yet'] || 'No OT Rate Sets have been added yet.' },
        initComplete: function () {
            addButtonInitComplete('btn-add-ot', 'fa-solid fa-plus', 'add_ot_rate', 'OT Rate Set', 'openOtModal()').call(this);
            // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
            // rollout, client mode. Excludes the interactive Default star (5), Status switch (6),
            // and actions (7).
            initExcelColumnFilters(this.api(), {
                mode: 'client',
                columns: [
                    { index: 0, key: 'name' },
                    { index: 1, key: 'weekday' },
                    { index: 2, key: 'weekend' },
                    { index: 3, key: 'holiday' },
                    { index: 4, key: 'assign' },
                ]
            });
        }
    });
}
function toggleOtStatus(id) {
    $.ajax({
        url: `${BASE_URL}/api/ot-rate.toggle-status`, method: 'POST', data: { id }, dataType: 'json',
        success: function (res) {
            if (!res.status) { showWarning(res.message || langData['ot_rate_set_default_blocked'] || langData['save_failed'] || 'An error occurred.'); }
            dtOt.ajax.reload(null, false);
        }
    });
}
function askOtSetDefault(id, name) {
    showConfirm(langData['ot_rate_set_make_default'] || 'Make Default', langData['ot_rate_set_make_default_confirm'] || 'Make this Set the company Default?', function () {
        $.ajax({
            url: `${BASE_URL}/api/ot-rate.set-default`, method: 'POST', data: { id }, dataType: 'json',
            success: function (res) {
                if (res.status) { showSuccess(res.message || langData['save_success'] || 'Saved successfully.'); dtOt.ajax.reload(null, false); }
                else { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
        });
    });
}
function applyOtItemRowFields($row, method) {
    const $rate = $row.find('.ot-item-rate');
    if (method === 'flat_amount') {
        $rate.attr({ step: '0.01', min: '0.01', placeholder: '100.00' });
    } else {
        $rate.attr({ step: '0.1', min: '0.1', placeholder: '1.5' });
    }
}
$(document).on('change', '.ot-item-method', function () {
    applyOtItemRowFields($(this).closest('tr'), $(this).val());
});
$(document).on('click', '#shiftWorkDaysToggle button', function () {
    $(this).toggleClass('active');
});
function otItemRowHtml(scope, item) {
    const method = (item && item.calculation_method) || 'multiplier';
    const base = (item && item.calculation_base) || 'hourly';
    const rate = item ? (method === 'flat_amount' ? item.flat_amount_rate : item.multiplier_rate) : (method === 'flat_amount' ? '' : 1.5);
    return `<tr data-scope-id="${scope.id}" data-scope-code="${scope.code}">
        <td class="fw-bold">${escapeHtmlSr(currentLang === 'th' ? scope.text_th : scope.text_en)}</td>
        <td><select class="form-select form-select-sm select2-static ot-item-method" data-option-keys="ot_calc_method_multiplier,ot_calc_method_flat_amount" data-option-values="multiplier,flat_amount"></select></td>
        <td><select class="form-select form-select-sm select2-static ot-item-base" data-option-keys="ot_base_hourly,ot_base_daily" data-option-values="hourly,daily"></select></td>
        <td>
            <div class="d-flex align-items-center gap-1">
                <input type="number" class="form-control form-control-sm ot-item-rate" step="${method === 'flat_amount' ? '0.01' : '0.1'}" min="${method === 'flat_amount' ? '0.01' : '0.1'}" value="${rate}">
                <button type="button" class="btn btn-link p-0 text-muted ot-item-preview-btn" title="${langData['calc_preview_button'] || 'Preview'}"><i class="fa-solid fa-calculator"></i></button>
            </div>
        </td>
    </tr>`;
}
function buildOtItemsBody(items) {
    return otScopeOptionsPromise().then(function (scopes) {
        const $body = $('#otItemsBody').empty();
        scopes.forEach(function (scope) {
            const item = (items || []).find(i => String(i.ot_scope_id) === String(scope.id)) || null;
            $body.append(otItemRowHtml(scope, item));
        });
        $body.find('.ot-item-method, .ot-item-base').each(function () { initSelect2(this, { mode: 'static' }); });
        $body.find('tr').each(function () {
            const $row = $(this);
            const item = (items || []).find(i => String(i.ot_scope_id) === String($row.data('scope-id'))) || null;
            const method = (item && item.calculation_method) || 'multiplier';
            $row.find('.ot-item-method').val(method).trigger('change.select2');
            $row.find('.ot-item-base').val((item && item.calculation_base) || 'hourly').trigger('change.select2');
            applyOtItemRowFields($row, method);
        });
    });
}
function otAssignListHtml(options, scopeType, checkedIds) {
    if (!options.length) { return `<div class="text-muted small">-</div>`; }
    return options.map(function (o) {
        const id = o.id;
        const checked = checkedIds.includes(String(id)) ? 'checked' : '';
        const label = String(o.label || '');
        return `<div class="form-check ot-assign-item" data-label="${escapeHtmlSr(label.toLowerCase())}">
            <input class="form-check-input ot-assign-checkbox" type="checkbox" value="${id}" data-scope-type="${scopeType}" id="ot_assign_${scopeType}_${id}" ${checked}>
            <label class="form-check-label small" for="ot_assign_${scopeType}_${id}">${escapeHtmlSr(label)}</label>
        </div>`;
    }).join('');
}
const OT_ASSIGN_LIST_ELS = { department: '#otAssignDepartments', team: '#otAssignTeams', position: '#otAssignPositions', employee: '#otAssignEmployees' };
function buildOtAssignLists(assignments) {
    const checkedByType = { department: [], team: [], position: [], employee: [] };
    (assignments || []).forEach(function (a) { if (checkedByType[a.scope_type]) { checkedByType[a.scope_type].push(String(a.scope_id)); } });
    return $.post(`${BASE_URL}/api/ot-rate.assignable-options`).then(function (res) {
        const data = (res && res.data) || { departments: [], teams: [], positions: [], employees: [] };
        $('#otAssignDepartments').html(otAssignListHtml(data.departments || [], 'department', checkedByType.department));
        $('#otAssignTeams').html(otAssignListHtml(data.teams || [], 'team', checkedByType.team));
        $('#otAssignPositions').html(otAssignListHtml(data.positions || [], 'position', checkedByType.position));
        $('#otAssignEmployees').html(otAssignListHtml(data.employees || [], 'employee', checkedByType.employee));
        // Reset search/select-all controls on every rebuild (edit vs. create vs. re-open) so stale
        // filter text/checked state from a previous modal open never carries over.
        $('.ot-assign-search').val('');
        $('.ot-assign-select-all').prop('checked', false);
    });
}
function applyOtDefaultToggleUi(isDefault) {
    $('#otAssignWrap').toggleClass('opacity-50', isDefault).find('input').prop('disabled', isDefault);
    $('#otAssignDisabledHint').toggleClass('d-none', !isDefault);
}
$(document).on('change', '#otIsDefault', function () {
    applyOtDefaultToggleUi($(this).is(':checked'));
});
// 2026-08-31, explicit follow-up ("ปรับ 3 จุดด้านบน") -- search + select-all per Assign-To column,
// same convention Payslip/Employment Certificate Template's own "Assign To" checkbox lists already
// use (pst_assign_*/ect_assign_* pattern) for companies with long department/team/position/employee
// lists. "Select All" only affects currently-VISIBLE (non-filtered-out) rows, matching that same
// established convention.
$(document).on('input', '.ot-assign-search', function () {
    const scopeType = $(this).data('scope-type');
    const term = $(this).val().toLowerCase().trim();
    const $list = $(OT_ASSIGN_LIST_ELS[scopeType]);
    $list.find('.ot-assign-item').each(function () {
        $(this).toggleClass('d-none', term !== '' && $(this).data('label').indexOf(term) === -1);
    });
});
$(document).on('change', '.ot-assign-select-all', function () {
    const scopeType = $(this).data('scope-type');
    const checked = $(this).is(':checked');
    $(OT_ASSIGN_LIST_ELS[scopeType]).find('.ot-assign-item:not(.d-none) .ot-assign-checkbox').prop('checked', checked);
});
function openOtModal(id) {
    $('#otModalTitle').html(`<i class="fa-solid fa-coins"></i> <span data-i18n="ot_rate">${langData['ot_rate'] || 'OT Rate'}</span>`);
    $('#otId').val('');
    $('#otNameTh').val(''); $('#otNameEn').val('');
    if (id) {
        $.ajax({
            url: `${BASE_URL}/api/ot-rate.get`, method: 'GET', data: { id }, dataType: 'json',
            success: function (res) {
                if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
                const o = res.data;
                $('#otId').val(o.id);
                $('#otNameTh').val(o.name_th);
                $('#otNameEn').val(o.name_en);
                $('#otIsDefault').prop('checked', !!o.is_default);
                applyOtDefaultToggleUi(!!o.is_default);
                $.when(buildOtItemsBody(o.items), buildOtAssignLists(o.assignments)).then(function () {
                    new bootstrap.Modal(document.getElementById('otModal')).show();
                });
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
        });
        return;
    }
    // 2026-08-30, explicit request: "ในหน้าจัดการ OT บังคับไปเลยดีกว่าครับว่าต้องมี Default...โดยระบบ
    // เลือกให้เลยในหน้าสร้าง แล้วให้ User เปลี่ยนเอง" -- pre-checked on create, user can uncheck (backend
    // still force-defaults this company's very first Set regardless of what's submitted).
    $('#otIsDefault').prop('checked', true);
    applyOtDefaultToggleUi(true);
    $.when(buildOtItemsBody([]), buildOtAssignLists([])).then(function () {
        new bootstrap.Modal(document.getElementById('otModal')).show();
    });
}
function collectOtItems() {
    const items = [];
    $('#otItemsBody tr').each(function () {
        const $row = $(this);
        const method = $row.find('.ot-item-method').val() || 'multiplier';
        const rate = parseFloat($row.find('.ot-item-rate').val());
        const item = { ot_scope_id: parseInt($row.data('scope-id')), calculation_method: method, calculation_base: $row.find('.ot-item-base').val() || 'hourly' };
        if (method === 'flat_amount') { item.flat_amount_rate = rate; } else { item.multiplier_rate = rate; }
        items.push(item);
    });
    return items;
}
function collectOtAssignments() {
    const assignments = [];
    $('.ot-assign-checkbox:checked').each(function () {
        assignments.push({ scope_type: $(this).data('scope-type'), scope_id: parseInt($(this).val()) });
    });
    return assignments;
}
function saveOt() {
    const nameTh = $('#otNameTh').val().trim();
    if (!nameTh) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const items = collectOtItems();
    for (const item of items) {
        const rate = item.calculation_method === 'flat_amount' ? item.flat_amount_rate : item.multiplier_rate;
        if (!rate || rate <= 0 || isNaN(rate)) {
            showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
            return;
        }
    }
    const payload = {
        id: $('#otId').val() || null,
        name_th: nameTh, name_en: $('#otNameEn').val().trim(),
        is_default: $('#otIsDefault').is(':checked'),
        items: items,
        assignments: collectOtAssignments(),
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

/* ==================== Calculation Preview (2026-08-30, per OT-type row) ====================
 * Same "ปุ่มแสดงตัวอย่างการคำนวณจากการตั้งค่าที่เลือก" feature already built for Attendance Deduction
 * Rule (public/js/setup/payroll-configuration.js), now scoped to ONE item row at a time (a Set bundles
 * all 3 types, so the preview button lives per-row instead of once per modal) -- posts whatever is
 * CURRENTLY typed in that row to api/ot-rate.preview (OtRateSetModel::previewCalculation(), the exact
 * same formula real OT payroll uses) against a fixed 30,000/2h sample scenario, shown in a small popup. */
function otCalcPreviewFormulaStepsHtml(formula) {
    if (!formula) return '';
    const fmt = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const baseLabel = formula.is_daily_base ? (langData['ot_base_daily'] || 'Daily') : (langData['ot_base_hourly'] || 'Hourly');
    if (formula.type === 'ot_flat') {
        const unitHours = formula.is_daily_base ? fmt(formula.hours / formula.hours_divisor) : fmt(formula.hours);
        return `<div class="calc-preview-step">${langData['calc_preview_step_base'] || 'Calculation Base'}: <code>${baseLabel}</code></div>
            <div class="calc-preview-step">${langData['calc_preview_step_rate'] || 'Rate'}: <code>${fmt(formula.flat_rate)}</code></div>
            <div class="calc-preview-step">${langData['calc_preview_step_formula'] || 'Formula'}: <code>${fmt(formula.flat_rate)} &times; ${unitHours}${formula.is_daily_base ? ' ' + (langData['unit_noun_day'] || 'day(s)') : ' ' + (langData['unit_noun_hour'] || 'hour(s)')} = ${fmt(formula.result)}</code></div>`;
    }
    if (formula.type === 'ot_multiplier') {
        return `<div class="calc-preview-step">${langData['calc_preview_step_base'] || 'Calculation Base'}: <code>${baseLabel}</code></div>
            <div class="calc-preview-step">${langData['calc_preview_step_unit_rate'] || 'Sample rate'}: <code>${fmt(formula.unit_rate)}</code></div>
            <div class="calc-preview-step">${langData['calc_preview_step_formula'] || 'Formula'}: <code>${fmt(formula.unit_rate)} &times; ${formula.multiplier} &times; ${fmt(formula.is_daily_base ? formula.hours / formula.hours_divisor : formula.hours)} = ${fmt(formula.result)}</code></div>`;
    }
    return '';
}
// 2026-08-31, explicit follow-up ("ปรับตัวอย่างการคำนวณ OT ในหน้าตั้งค่า OT Form ดูไม่ Balance ปรับให้
// Form ตรงกัน และกดคำนวณแล้วให้ขึ้น Block ตัวอย่างต่อลงมาเลย ไม่ต้องเปิดตัวใหม่ ให้เป็น modal เดียวไปเลย") --
// ONE Swal.fire now handles the whole flow: 2 sample inputs laid out SIDE BY SIDE (was stacked full-
// width, looked "unbalanced" against each other) with a Calculate button that appends the result
// block directly below them IN THE SAME modal instead of closing it and opening a second one.
// Achieved via preConfirm always returning `false` (SweetAlert2's own "keep the popup open" signal)
// after injecting the result HTML into a placeholder that's already part of this SAME html -- only
// Cancel/the X button actually closes it, so "Calculate" can be clicked repeatedly with different
// sample values without ever losing the row's own calculation_method/base/rate context.
function otItemPreviewPromptHtml() {
    return `<div class="text-start ot-preview-swal-body">
        <div class="row g-2">
            <div class="col-6">
                <label class="form-label small mb-1" data-i18n="calc_preview_sample_base_salary">${langData['calc_preview_sample_base_salary'] || 'Sample Base Salary'}</label>
                <input type="number" min="1" step="0.01" class="swal2-input m-0" id="swalOtPreviewBaseSalary" value="30000">
            </div>
            <div class="col-6">
                <label class="form-label small mb-1" data-i18n="calc_preview_sample_ot_hours">${langData['calc_preview_sample_ot_hours'] || 'Sample OT Hours'}</label>
                <input type="number" min="0" step="0.5" class="swal2-input m-0" id="swalOtPreviewHours" value="2">
            </div>
        </div>
        <div class="calc-preview-result d-none mt-3" id="swalOtPreviewResult"></div>
    </div>`;
}
$(document).on('click', '.ot-item-preview-btn', function () {
    const $row = $(this).closest('tr');
    const calcMethod = $row.find('.ot-item-method').val() || 'multiplier';
    const rate = parseFloat($row.find('.ot-item-rate').val());
    const calcBase = $row.find('.ot-item-base').val() || 'hourly';
    Swal.fire({
        icon: 'question', title: langData['calc_preview_title'] || 'Calculation Preview',
        html: otItemPreviewPromptHtml(),
        showCancelButton: true,
        confirmButtonText: langData['calc_preview_button'] || 'Preview',
        cancelButtonText: langData['close'] || 'Close',
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        preConfirm: () => {
            const baseSalary = parseFloat(document.getElementById('swalOtPreviewBaseSalary').value);
            const hours = parseFloat(document.getElementById('swalOtPreviewHours').value);
            if (!baseSalary || baseSalary <= 0 || isNaN(hours) || hours < 0) {
                Swal.showValidationMessage(langData['required_star_message'] || 'Please fill all fields marked with *');
                return false;
            }
            const payload = {
                calculation_method: calcMethod, calculation_base: calcBase,
                sample_base_salary: baseSalary, sample_hours: hours,
            };
            if (calcMethod === 'flat_amount') { payload.flat_amount_rate = rate || 0; } else { payload.multiplier_rate = rate || 1.5; }
            return $.ajax({
                url: `${BASE_URL}/api/ot-rate.preview`, method: 'POST', contentType: 'application/json', data: JSON.stringify(payload), dataType: 'json',
            }).then(function (res) {
                if (!res.status) {
                    Swal.showValidationMessage(res.message || langData['save_failed'] || 'An error occurred.');
                    return false;
                }
                const amount = Number(res.amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                $('#swalOtPreviewResult').removeClass('d-none').html(
                    `<div class="calc-preview-amount mb-1">${langData['calc_preview_result_label'] || 'Result'}: ${amount}</div>` +
                    otCalcPreviewFormulaStepsHtml(res.formula)
                );
                return false; // keep the modal open -- Calculate never "confirms"/closes it.
            }, function () {
                Swal.showValidationMessage(langData['save_failed'] || 'An error occurred while calculating the preview.');
                return false;
            });
        }
    });
});

$(function () {
    initHolidayScopeSelects();
    renderShift();
    renderHoliday();
    renderLeave();
    renderOt();
    renderWorkLocation();
});
