/**
 * Generic "Assign Employees" modal (#structureAssignModal, markup in app/views/layout/modals.php,
 * loaded on every page via footer.php's own global modal include) -- 2026-08-31, explicit request:
 * "เพิ่มปุ่มให้สามารถ Assign ได้ โดยเปิดเป็น Modal ขึ้นมา มีรายละเอียด Master Data แล้วแบ่งเป็น 2 Card คือ
 * พนักงานที่อยู่ Master อื่น และพนักงานที่อยู่ Master นี้ สามารถดึงพนักงานที่อยู่ Master อื่นเข้ามาใน Master ที่
 * เลือกได้เลย และสามารถนำพนักงานที่อยู่ใน Master นี้ย้ายออกไปได้ โดยถ้าย้ายออกหรือย้ายเข้าให้มี sweetalert
 * confirm ก่อน และถ้าย้ายออกใน sweetalert มี select2 ของ master นั้นให้เลือกว่าจะเลือกย้ายไปที่ Master ไหน และมี
 * Recomment ว่าถ้าไม่เลือกพนักงานจะไม่มีสังกัด...และมีอีกปุ่มสำหรับกด View เพื่อดูเฉพาะพนักงานที่อยู่ใน Master นั้น
 * และสามารถย้ายออกไปจาก Master ได้ โดยทำให้ครบทุก Master ที่สามารถ Assign ให้พนักงานได้".
 *
 * ONE shared component across all 8 assignable master types -- 6 via CompanyProfileModel's
 * generic structureConfig() (branch/role/department/position/rank/team, api/structure.assign.*
 * routes) + 2 via SetupRulesModel's own mirror (shift/work_location, api/scope.assign.* routes).
 * `SA_ROUTE_PREFIX` is the only place that distinction lives -- every other function here reads
 * purely from `saCurrentType`/`saCurrentRowId`, no per-type branching anywhere else.
 */
const SA_ROUTE_PREFIX = {
    branch: 'structure', role: 'structure', department: 'structure', position: 'structure', rank: 'structure', team: 'structure',
    shift: 'scope', work_location: 'scope',
};
// Select2-ajax dropdown-options endpoint per type, for the Move Out SweetAlert's own destination
// picker -- same endpoints this app's other pages already use for these exact dropdowns (Employee
// Detail's own department/position/etc selects), see MasterModel::master()'s own comment for rank/
// work_location (the 2 that needed a new endpoint added this same batch).
const SA_DESTINATION_API = {
    branch: '/api/branch.get', role: '/api/role.get', department: '/api/department.get', position: '/api/position.get',
    rank: '/api/rank.get', team: '/api/team.get', shift: '/api/shift.options', work_location: '/api/work-location.options',
};

let saCurrentType = null;
let saCurrentRowId = null;
let saCurrentLabel = '';
let saMode = 'assign';

function escapeHtmlSa(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}
function saRoutePrefix() {
    return SA_ROUTE_PREFIX[saCurrentType] || 'structure';
}
function saEmployeeDisplayName(row) {
    return currentLang === 'th' ? `${row.name_th || ''} ${row.surname_th || ''}`.trim() : `${row.name_en || ''} ${row.surname_en || ''}`.trim();
}

/**
 * Opens the modal. `mode` is 'assign' (both cards, the normal Assign button) or 'view' (only the
 * "employees in this Master" card + Move Out -- the separate View button's own entry point, per
 * the explicit request above). `label` is the row's own display name, shown in the modal title and
 * carried into the confirm dialogs so an admin can read exactly what they're about to change.
 */
function openStructureAssignModal(type, id, label, mode) {
    saCurrentType = type;
    saCurrentRowId = id;
    saCurrentLabel = label || '';
    saMode = mode === 'view' ? 'view' : 'assign';
    $('#saOutsideCard').toggleClass('d-none', saMode === 'view');
    const titleKey = saMode === 'view' ? 'view_assigned_employees' : 'assign_employees';
    $('#structureAssignModalTitle').text(`${langData[titleKey] || (saMode === 'view' ? 'View Assigned Employees' : 'Assign Employees')} — ${saCurrentLabel}`);
    $('#saOutsideSearch, #saInSearch').val('').attr('placeholder', langData['search'] || 'Search...');
    loadSaInList();
    if (saMode === 'assign') {
        loadSaOutsideList();
    }
    new bootstrap.Modal(document.getElementById('structureAssignModal')).show();
}

function saRenderList($container, rows, emptyKey, listPrefix) {
    if (!rows.length) {
        $container.html(`<div class="text-center text-secondary small py-3">${langData[emptyKey] || 'No employees.'}</div>`);
        return;
    }
    $container.html(rows.map(row => {
        const badge = row.current_row_name
            ? `<span class="badge bg-secondary-subtle text-secondary ms-1">${escapeHtmlSa(row.current_row_name)}</span>`
            : (row.current_row_id === null || row.current_row_id === undefined ? `<span class="badge bg-light text-muted ms-1">${langData['sa_unassigned'] || 'Unassigned'}</span>` : '');
        const cbId = `saEmpChk_${listPrefix}_${row.id}`;
        return `<div class="form-check border-bottom py-1">
            <input class="form-check-input sa-emp-checkbox" type="checkbox" value="${row.id}" id="${cbId}">
            <label class="form-check-label small w-100" for="${cbId}">${escapeHtmlSa(row.employee_no)} - ${escapeHtmlSa(saEmployeeDisplayName(row))}${badge}</label>
        </div>`;
    }).join(''));
}

function loadSaInList() {
    const search = $('#saInSearch').val() || '';
    $.get(`${BASE_URL}/api/${saRoutePrefix()}.assign.employees-in`, { type: saCurrentType, id: saCurrentRowId, search }, function (res) {
        if (!res.status) { showWarning(res.message || langData['load_failed'] || 'Failed to load data.'); return; }
        saRenderList($('#saInList'), res.data || [], 'sa_no_employees_in', 'in');
        $('#btnSaMoveOut').prop('disabled', true);
    }, 'json');
}
function loadSaOutsideList() {
    const search = $('#saOutsideSearch').val() || '';
    $.get(`${BASE_URL}/api/${saRoutePrefix()}.assign.employees-outside`, { type: saCurrentType, id: saCurrentRowId, search }, function (res) {
        if (!res.status) { showWarning(res.message || langData['load_failed'] || 'Failed to load data.'); return; }
        saRenderList($('#saOutsideList'), res.data || [], 'sa_no_employees_outside', 'out');
        $('#btnSaPullIn').prop('disabled', true);
    }, 'json');
}

$(document).on('input', '#saInSearch', function () { loadSaInList(); });
$(document).on('input', '#saOutsideSearch', function () { loadSaOutsideList(); });
$(document).on('change', '#saInList .sa-emp-checkbox', function () {
    $('#btnSaMoveOut').prop('disabled', $('#saInList .sa-emp-checkbox:checked').length === 0);
});
$(document).on('change', '#saOutsideList .sa-emp-checkbox', function () {
    $('#btnSaPullIn').prop('disabled', $('#saOutsideList .sa-emp-checkbox:checked').length === 0);
});

$(document).on('click', '.btn-structure-assign', function () {
    openStructureAssignModal($(this).data('type'), $(this).data('id'), $(this).data('label'), 'assign');
});
$(document).on('click', '.btn-structure-view-assigned', function () {
    openStructureAssignModal($(this).data('type'), $(this).data('id'), $(this).data('label'), 'view');
});

$(document).on('click', '#btnSaPullIn', function () {
    const ids = $('#saOutsideList .sa-emp-checkbox:checked').map(function () { return parseInt($(this).val(), 10); }).get();
    if (!ids.length) return;
    const title = langData['sa_confirm_pull_in_title'] || 'Pull In Employees';
    const message = (langData['sa_confirm_pull_in_message'] || 'Move {count} employee(s) into "{label}"?').replace('{count}', ids.length).replace('{label}', saCurrentLabel);
    showConfirm(title, message, function () {
        $.ajax({
            url: `${BASE_URL}/api/${saRoutePrefix()}.assign.assign`, method: 'POST', contentType: 'application/json',
            data: JSON.stringify({ type: saCurrentType, id: saCurrentRowId, employee_ids: ids }), dataType: 'json',
            success: function (res) {
                if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
                showSuccess(langData['save_success'] || 'Saved successfully.');
                loadSaInList();
                loadSaOutsideList();
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
        });
    });
});

// 2026-08-31, explicit request: "ถ้าย้ายออกใน sweetalert มี select2 ของ master นั้นให้เลือกว่าจะเลือกย้ายไปที่
// Master ไหน และมี Recomment ว่าถ้าไม่เลือกพนักงานจะไม่มีสังกัด" -- the select2 lives INSIDE the SweetAlert2
// dialog itself (its own `html`), initialized in `didOpen` via the same shared initSelect2() every
// other select2-remote field in this app uses -- SweetAlert2's popup isn't a `.modal` so
// initSelect2()'s own dropdownParent auto-detection falls back to body, which is fine here (the
// popup already sits above everything). Leaving the destination blank is a real, supported choice
// (unassign), not a validation error -- the hint text below says so instead of blocking it.
$(document).on('click', '#btnSaMoveOut', function () {
    const ids = $('#saInList .sa-emp-checkbox:checked').map(function () { return parseInt($(this).val(), 10); }).get();
    if (!ids.length) return;
    const title = (langData['sa_confirm_move_out_title'] || 'Move Out of {label}').replace('{label}', saCurrentLabel);
    const destApi = SA_DESTINATION_API[saCurrentType] || '';
    Swal.fire({
        title: title,
        html: `<div class="text-start">
            <p>${(langData['sa_confirm_move_out_message'] || 'Move {count} employee(s) out of "{label}"?').replace('{count}', ids.length).replace('{label}', saCurrentLabel)}</p>
            <label class="form-label small fw-bold">${langData['sa_move_to_label'] || 'Move to'}</label>
            <select class="form-select" id="swalSaDestSelect" data-api="${destApi}" data-type="${saCurrentType}"></select>
            <div class="form-text mt-1">${langData['sa_move_to_blank_hint'] || 'Leave blank and the employee will have no assignment.'}</div>
        </div>`,
        showCancelButton: true,
        confirmButtonText: langData['confirm'] || 'Confirm',
        cancelButtonText: langData['cancel'] || 'Cancel',
        didOpen: function () {
            if (typeof initSelect2 === 'function') {
                initSelect2('#swalSaDestSelect', { mode: 'ajax', allowClear: true });
            }
        },
        preConfirm: function () {
            const val = $('#swalSaDestSelect').val();
            return val ? parseInt(val, 10) : null;
        }
    }).then(function (result) {
        if (!result.isConfirmed) return;
        $.ajax({
            url: `${BASE_URL}/api/${saRoutePrefix()}.assign.move-out`, method: 'POST', contentType: 'application/json',
            data: JSON.stringify({ type: saCurrentType, employee_ids: ids, destination_id: result.value }), dataType: 'json',
            success: function (res) {
                if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
                showSuccess(langData['save_success'] || 'Saved successfully.');
                loadSaInList();
                if (saMode === 'assign') {
                    loadSaOutsideList();
                }
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
        });
    });
});
