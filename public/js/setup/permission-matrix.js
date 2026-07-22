/**
 * RBAC permission matrix -- lives inside Company Setup > Organizational Structure > Permissions
 * tab (6th structure-tab, page 'p6' in company-profile.js's initStructure()). Not a DataTable --
 * a hand-built table since it's a fixed 8-permission x N-role grid, not a paginated record list.
 *
 * allow_scope selector only shown for approval_request.act -- Holiday/Leave Type are company-wide
 * config with no per-record department ownership, so "own department" has no enforcement meaning
 * for them (see PermissionModel docblock / CLAUDE.md).
 */
function initPermissionMatrix() {
    $('#permissionMatrixContainer').html(`<div class="text-center text-muted py-5"><i class="fa-solid fa-spinner fa-spin"></i></div>`);
    $.ajax({
        url: `${BASE_URL}/api/permission-matrix.get`, method: 'GET', dataType: 'json',
        success: function (res) {
            if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
            renderPermissionMatrix(res.data);
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
}

function escapeHtmlPm(str) {
    return $('<div>').text(str || '').html().replace(/"/g, '&quot;');
}

function permissionModuleLabel(code) {
    const map = {
        holiday: langData['holiday'] || 'Holiday',
        leave_type: langData['leave_type'] || 'Leave Type',
        approval_workflow: langData['approval_workflow'] || 'Approval Workflow',
        approval_request: langData['approval_monitor'] || 'Approval Monitor',
        rbac: langData['permissions'] || 'Permissions',
    };
    return map[code] || code;
}

function renderPermissionMatrix(data) {
    const roles = data.roles || [];
    const permissions = data.permissions || [];
    const grants = data.grants || [];
    const $container = $('#permissionMatrixContainer');

    if (!roles.length) {
        $container.html(`<div class="text-center text-muted py-5">
            <i class="fa-solid fa-user-tag fa-2x mb-2"></i>
            <p class="mb-0">${langData['no_roles_for_matrix'] || 'No roles found. Create a role in the Role tab first.'}</p>
        </div>`);
        return;
    }

    const grantMap = {};
    grants.forEach(g => { grantMap[g.role_id + ':' + g.permission_id] = g.allow_scope; });

    const modules = {};
    const moduleOrder = [];
    permissions.forEach(p => {
        if (!modules[p.module_code]) { modules[p.module_code] = []; moduleOrder.push(p.module_code); }
        modules[p.module_code].push(p);
    });

    let html = `<table class="table table-bordered align-middle permission-matrix-table" id="tb_permission_matrix">
        <thead class="table-light"><tr><th style="min-width:220px;">${langData['permission'] || 'Permission'}</th>`;
    roles.forEach(r => {
        html += `<th class="text-center">${escapeHtmlPm(currentLang === 'th' ? r.role_name_th : r.role_name_en)}</th>`;
    });
    html += `</tr></thead><tbody>`;

    moduleOrder.forEach(moduleCode => {
        html += `<tr class="table-light"><td colspan="${roles.length + 1}"><strong>${escapeHtmlPm(permissionModuleLabel(moduleCode))}</strong></td></tr>`;
        modules[moduleCode].forEach(p => {
            html += `<tr><td>${escapeHtmlPm(currentLang === 'th' ? p.name_th : p.name_en)}</td>`;
            const showScope = p.permission_key === 'approval_request.act';
            roles.forEach(r => {
                const scope = grantMap[r.id + ':' + p.id];
                const checked = scope !== undefined;
                html += `<td class="text-center">
                    <div class="d-flex flex-column align-items-center gap-1">
                        <input type="checkbox" class="form-check-input perm-cell" data-role-id="${r.id}" data-permission-id="${p.id}" ${checked ? 'checked' : ''}>`;
                if (showScope) {
                    html += `<select class="form-select form-select-sm perm-scope-select ${checked ? '' : 'd-none'}" style="width:auto;" data-role-id="${r.id}" data-permission-id="${p.id}">
                            <option value="all" ${scope === 'own_department' ? '' : 'selected'}>${langData['scope_all'] || 'All'}</option>
                            <option value="own_department" ${scope === 'own_department' ? 'selected' : ''}>${langData['scope_own_department'] || 'Own Dept.'}</option>
                        </select>`;
                }
                html += `</div></td>`;
            });
            html += `</tr>`;
        });
    });
    html += `</tbody></table>`;
    $container.html(html);
}

$(document).on('change', '.perm-cell', function () {
    const $scope = $(this).closest('td').find('.perm-scope-select');
    $scope.toggleClass('d-none', !this.checked);
});

$(document).on('click', '#btnSavePermissionMatrix', function () {
    const grants = [];
    $('.perm-cell:checked').each(function () {
        const roleId = $(this).data('roleId');
        const permissionId = $(this).data('permissionId');
        const $scope = $(`.perm-scope-select[data-role-id="${roleId}"][data-permission-id="${permissionId}"]`);
        const allowScope = $scope.length ? $scope.val() : 'all';
        grants.push({ role_id: roleId, permission_id: permissionId, allow_scope: allowScope });
    });
    $.ajax({
        url: `${BASE_URL}/api/permission-matrix.save`, method: 'POST', contentType: 'application/json',
        data: JSON.stringify({ grants }), dataType: 'json',
        success: function (res) {
            if (res.status) { showSuccess(res.message || langData['save_success'] || 'Saved successfully.'); }
            else { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
});
