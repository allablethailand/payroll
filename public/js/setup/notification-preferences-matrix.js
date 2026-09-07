/**
 * Notification Preferences role-default matrix -- lives inside Company Setup > Organizational
 * Structure > Permissions tab, right below the Permission Matrix (same tab, own section -- see
 * company-profile.php's own tmpl-permission-pane). Direct structural clone of permission-matrix.js
 * (5 notification types x N roles grid instead of 8 permissions x N roles), with ONE deliberate
 * difference in the save handler: Permission Matrix only submits CHECKED cells (unchecked = no
 * grant row = deny), but this grid's own default polarity is the OPPOSITE (no row = enabled) -- so
 * every rendered cell, checked or not, is submitted explicitly. See
 * NotificationModel::saveRoleMatrix()'s own docblock for the full reasoning.
 */
function initNotificationRoleMatrix() {
    $('#notificationRoleMatrixContainer').html(`<div class="text-center text-muted py-5"><i class="fa-solid fa-spinner fa-spin"></i></div>`);
    $.ajax({
        url: `${BASE_URL}/api/notification.role-matrix-get`, method: 'GET', dataType: 'json',
        success: function (res) {
            if (!res.status) { $('#notificationRoleMatrixContainer').html(''); return; }
            renderNotificationRoleMatrix(res.data);
        },
        error: function () { $('#notificationRoleMatrixContainer').html(''); }
    });
}


function renderNotificationRoleMatrix(data) {
    const roles = data.roles || [];
    const types = data.types || [];
    const grants = data.grants || [];
    const $container = $('#notificationRoleMatrixContainer');

    if (!roles.length) {
        $container.html(`<div class="text-center text-muted py-5">
            <i class="fa-solid fa-user-tag fa-2x mb-2"></i>
            <p class="mb-0">${langData['no_roles_for_matrix'] || 'No roles found. Create a role in the Role tab first.'}</p>
        </div>`);
        return;
    }

    const grantMap = {};
    grants.forEach(g => { grantMap[g.role_id + ':' + g.type] = !!g.enabled; });

    let html = `<table class="table table-bordered align-middle permission-matrix-table" id="tb_notification_role_matrix">
        <thead class="table-light"><tr><th style="min-width:260px;">${langData['notifications'] || 'Notification'}</th>`;
    roles.forEach(r => {
        html += `<th class="text-center">${escapeHtml(currentLang === 'th' ? r.role_name_th : r.role_name_en)}</th>`;
    });
    html += `</tr></thead><tbody>`;

    types.forEach(t => {
        html += `<tr><td>${escapeHtml(currentLang === 'th' ? t.label_th : t.label_en)}</td>`;
        roles.forEach(r => {
            // No row for this (role, type) yet = the true default = enabled -- same "no row = on"
            // convention shouldNotify() itself uses server-side.
            const key = r.id + ':' + t.type;
            const enabled = Object.prototype.hasOwnProperty.call(grantMap, key) ? grantMap[key] : true;
            html += `<td class="text-center">
                <input type="checkbox" class="form-check-input notif-role-cell" data-role-id="${r.id}" data-type="${escapeHtml(t.type)}" ${enabled ? 'checked' : ''}>
            </td>`;
        });
        html += `</tr>`;
    });
    html += `</tbody></table>`;
    $container.html(html);
}

$(document).on('click', '#btnSaveNotificationRoleMatrix', function () {
    const grants = [];
    // Every rendered cell, not just checked ones -- see this file's own top-of-file docblock.
    $('.notif-role-cell').each(function () {
        grants.push({
            role_id: $(this).data('roleId'),
            type: $(this).data('type'),
            enabled: this.checked,
        });
    });
    $.ajax({
        url: `${BASE_URL}/api/notification.role-matrix-save`, method: 'POST', contentType: 'application/json',
        data: JSON.stringify({ grants }), dataType: 'json',
        success: function (res) {
            if (res.status) { showSuccess(res.message || langData['save_success'] || 'Saved successfully.'); }
            else { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
});
