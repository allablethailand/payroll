/**
 * RBAC permission matrix -- 2026-08-31: moved out of Company Setup > Organizational Structure's
 * own "Permissions" pill into its own standalone top-level page (app/views/setup/permissions.php,
 * setup/permissions route). Not a DataTable -- a hand-built table since it's a fixed permission
 * list x N-role grid, not a paginated record list.
 *
 * 2026-08-31, explicit request ("แยก Tab ตามกลุ่มภายในอีกที"): permissions are grouped into real
 * pill-tab panes by module_code now (was: all modules rendered into one long scrollable table with
 * <tr class="table-light"> section-header rows in between). All permissions/grants are still
 * fetched ONCE (`pmMatrixData`) -- switching module tabs is a pure client-side re-render, no
 * re-fetch. Every checkbox/scope-select change updates `pmState` (a role:permission -> allow_scope
 * map) immediately, NOT read fresh from the DOM at Save time -- the DOM only ever holds the
 * CURRENTLY ACTIVE module's rows, so a plain `$('.perm-cell:checked')` read at Save would silently
 * lose any edit made on a module tab the admin has since switched away from. `pmState` is the one
 * source of truth for what to save, always.
 *
 * allow_scope selector only shown for approval_request.act -- Holiday/Leave Type are company-wide
 * config with no per-record department ownership, so "own department" has no enforcement meaning
 * for them (see PermissionModel docblock / CLAUDE.md).
 */
let pmMatrixData = null;
let pmState = {};
let pmModules = {};
let pmModuleOrder = [];
let pmActiveModule = null;
// 2026-09-02, Platform Hardening Phase 1.2 -- dirty-check baseline for the new Cancel button.
// pmState IS the single source of truth for every edit (see docblock above), so comparing its own
// serialized form is simpler and more reliable here than a DOM snapshot of whichever module tab
// happens to be showing right now.
let pmBaselineSnapshot = null;

function initPermissionMatrix() {
    $('#permissionModuleTabs').empty();
    $('#permissionMatrixContainer').html(`<div class="text-center text-muted py-5"><i class="fa-solid fa-spinner fa-spin"></i></div>`);
    $.ajax({
        url: `${BASE_URL}/api/permission-matrix.get`, method: 'GET', dataType: 'json',
        success: function (res) {
            if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
            setupPermissionMatrixData(res.data);
            renderPermissionModuleTabs();
            renderPermissionMatrixTable(pmActiveModule);
            pmBaselineSnapshot = JSON.stringify(pmState);
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
}
$(document).on('click', '#btnCancelPermissionMatrix', function () {
    if (pmBaselineSnapshot !== null && JSON.stringify(pmState) === pmBaselineSnapshot) {
        initPermissionMatrix();
        return;
    }
    showConfirm(
        (langData && langData['confirm_discard_changes_title']) || 'Discard unsaved changes?',
        (langData && langData['confirm_discard_changes_message']) || "You have changes that haven't been saved yet. If you continue, they will be lost.",
        initPermissionMatrix
    );
});

function escapeHtmlPm(str) {
    return $('<div>').text(str || '').html().replace(/"/g, '&quot;');
}

// 2026-08-28, real bug found and fixed (explicit report: "ในหน้าจัดการสิทธิ์การใช้งาน บางคำยังเป็นคีย์
// ยังไม่แปล" -- some words on the Permission Matrix page still show as raw keys, not translated).
// Root cause: this map only ever covered the original 5 module_codes from when this feature first
// shipped -- the `permissions` table has since grown, and the fallback `map[code] || code` silently
// rendered the raw snake_case module_code string for any code that was never added here -- which
// reads exactly like an untranslated i18n key even though it technically isn't one.
//
// 2026-09-02, SAME bug found again, same root cause -- the `permissions` table had grown to 19
// distinct module_codes by then (email_queue/employee_login_log/payroll_run_cash_payment/
// payroll_sync/reports/salary_amount added across several later rounds), 6 of which were still
// missing from this map. Checked a live `SELECT DISTINCT module_code FROM permissions` against this
// map directly this time (not just eyeballing the page) to make sure nothing else is missing.
// 5 of the 6 reuse existing generic i18n keys already used elsewhere in this app (same "checked
// first" rule as the 2026-08-28 fix); `salary_amount` genuinely has no existing generic label to
// reuse (closest is the bare word "salary", too generic for a permission-module tab specifically
// about visibility of salary figures) so it gets ONE new key, `permission_module_salary_amount`.
function permissionModuleLabel(code) {
    const map = {
        holiday: langData['holiday'] || 'Holiday',
        leave_type: langData['leave_type'] || 'Leave Type',
        approval_workflow: langData['approval_workflow'] || 'Approval Workflow',
        approval_request: langData['approval_monitor'] || 'Approval Monitor',
        rbac: langData['permissions_menu'] || langData['permissions'] || 'Permissions',
        employee: langData['employee'] || 'Employee',
        company_structure: langData['organization_structure'] || 'Organization Structure',
        bank_account: langData['bank_account'] || 'Bank Account',
        payslip_template: langData['payslip_template'] || 'Payslip Template',
        payroll_configuration: langData['payroll_configuration'] || 'Payroll Configuration',
        tax_statutory: langData['local_statutory_and_tax_settings'] || 'Local Statutory & Tax Settings',
        company_profile: langData['company_profile'] || 'Company Profile',
        employment_certificate_template: langData['employment_certificate_template'] || 'Employment Certificate Template',
        email_queue: langData['email_queue_log'] || 'Email Queue Log',
        employee_login_log: langData['login_history'] || 'Login History',
        payroll_run_cash_payment: langData['tab_cash_payments'] || 'Cash Payments',
        payroll_sync: langData['origami_sync_summary_title'] || 'Origami Sync',
        reports: langData['reports'] || 'Reports',
        salary_amount: langData['permission_module_salary_amount'] || 'Salary Amount Visibility',
        // 2026-09-02, added alongside PayrollController/ReportsController's new payroll_run.view/
        // .manage permissions -- reuses the SAME i18n key the Payroll Process page's own breadcrumb/
        // header already use (payroll_process), not the also-existing but less specific "payroll_run"
        // key (that one's used elsewhere for the raw table/row concept, not the module/menu name).
        payroll_run: langData['payroll_process'] || 'Payroll Process',
        // 2026-09-03, Platform Hardening Phase 3 Stage 3 -- added alongside the new shift.*/
        // work_location.*/ot_rate.* permissions (SetupRulesController's Shift/Work Location/OT Rate
        // tabs newly gated this stage). Checked this time against a live `SELECT DISTINCT
        // module_code FROM permissions` before shipping, same as the 2026-09-02 fix's own note --
        // all 3 reuse existing generic i18n keys, no new key needed.
        shift: langData['shift'] || 'Shift',
        work_location: langData['work_location'] || 'Work Location',
        ot_rate: langData['ot_rate'] || 'OT Rate',
    };
    return map[code] || code;
}

// 2026-08-31, explicit request ("สิทธิ์ในการมองเห็นเงินเดือน...แยกสิทธิ์ย่อยหลายระดับ"): pmState[key] is
// now an {scope, detail_level} object, not a bare scope string -- widened to also carry
// detail_level ('summary'|'full', only meaningful for salary_amount.* keys, see PermissionModel's
// own docblock). Key presence in pmState still means "checked", same as before.
function setupPermissionMatrixData(data) {
    pmMatrixData = data;
    pmState = {};
    (data.grants || []).forEach(g => {
        pmState[g.role_id + ':' + g.permission_id] = { scope: g.allow_scope, detail_level: g.detail_level || 'full' };
    });
    pmModules = {};
    pmModuleOrder = [];
    (data.permissions || []).forEach(p => {
        if (!pmModules[p.module_code]) { pmModules[p.module_code] = []; pmModuleOrder.push(p.module_code); }
        pmModules[p.module_code].push(p);
    });
    pmActiveModule = pmModuleOrder[0] || null;
}

function renderPermissionModuleTabs() {
    const $tabs = $('#permissionModuleTabs');
    if (!pmModuleOrder.length) { $tabs.empty(); return; }
    let html = '';
    pmModuleOrder.forEach((code, idx) => {
        const active = code === pmActiveModule ? 'active' : '';
        html += `<li class="nav-item" role="presentation">
            <button class="nav-link structure-menu ${active}" type="button" role="tab" data-module="${escapeHtmlPm(code)}">
                ${escapeHtmlPm(permissionModuleLabel(code))}
            </button>
        </li>`;
    });
    $tabs.html(html);
}

function renderPermissionMatrixTable(moduleCode) {
    const roles = (pmMatrixData && pmMatrixData.roles) || [];
    const $container = $('#permissionMatrixContainer');

    if (!roles.length) {
        $container.html(`<div class="text-center text-muted py-5">
            <i class="fa-solid fa-user-tag fa-2x mb-2"></i>
            <p class="mb-0">${langData['no_roles_for_matrix'] || 'No roles found. Create a role in the Role tab first.'}</p>
        </div>`);
        return;
    }

    const rows = pmModules[moduleCode] || [];
    // 2026-09-02, explicit request ("ช่วยดูเรื่อง Design ให้อีกครั้ง...มีส่วนไหนที่ควรเพิ่มอีกบ้าง") -- a
    // per-role "select all" checkbox in the column header, so granting a role every permission in
    // the CURRENTLY OPEN module tab (a common real action -- e.g. a new "HR Manager" role that
    // should get every Employee permission at once) doesn't mean clicking each row one at a time.
    // Scoped to the active module tab only (mirrors how pmState itself is edited one module at a
    // time) -- reuses the SAME .perm-cell change handler per checkbox (via .trigger('change')) so
    // pmState/scope-select visibility stay correct through the one code path that already handles
    // it, instead of a second, parallel state-mutation path that could drift out of sync.
    let html = `<table class="table table-bordered align-middle permission-matrix-table" id="tb_permission_matrix">
        <thead class="table-light"><tr><th style="min-width:220px;">${langData['permission'] || 'Permission'}</th>`;
    roles.forEach(r => {
        html += `<th class="text-center">
            <div>${escapeHtmlPm(currentLang === 'th' ? r.role_name_th : r.role_name_en)}</div>
            <input type="checkbox" class="form-check-input perm-role-select-all" data-role-id="${r.id}" title="${langData['select_all'] || 'Select All'}">
        </th>`;
    });
    html += `</tr></thead><tbody>`;

    // 2026-08-31: salary_amount.* keys get the SAME scope selector approval_request.act already
    // has (widened to a 3rd 'own_only' option, only offered here -- 'own_department' has no
    // sensible meaning for a salary-visibility grant the way it does for approval routing) PLUS a
    // second detail_level selector neither key had before.
    rows.forEach(p => {
        html += `<tr><td>${escapeHtmlPm(currentLang === 'th' ? p.name_th : p.name_en)}</td>`;
        const isApprovalAct = p.permission_key === 'approval_request.act';
        const isSalaryAmount = p.permission_key.indexOf('salary_amount.') === 0;
        roles.forEach(r => {
            const key = r.id + ':' + p.id;
            const state = pmState[key];
            const checked = state !== undefined;
            const scope = state ? state.scope : 'all';
            const detailLevel = state ? state.detail_level : 'full';
            html += `<td class="text-center">
                <div class="d-flex flex-column align-items-center gap-1">
                    <input type="checkbox" class="form-check-input perm-cell" data-role-id="${r.id}" data-permission-id="${p.id}" ${checked ? 'checked' : ''}>`;
            if (isApprovalAct) {
                html += `<select class="form-select form-select-sm perm-scope-select ${checked ? '' : 'd-none'}" style="width:auto;" data-role-id="${r.id}" data-permission-id="${p.id}">
                        <option value="all" ${scope === 'own_department' ? '' : 'selected'}>${langData['scope_all'] || 'All'}</option>
                        <option value="own_department" ${scope === 'own_department' ? 'selected' : ''}>${langData['scope_own_department'] || 'Own Dept.'}</option>
                    </select>`;
            } else if (isSalaryAmount) {
                html += `<select class="form-select form-select-sm perm-scope-select ${checked ? '' : 'd-none'}" style="width:auto;" data-role-id="${r.id}" data-permission-id="${p.id}">
                        <option value="all" ${scope === 'all' ? 'selected' : ''}>${langData['scope_all'] || 'All'}</option>
                        <option value="own_only" ${scope === 'own_only' ? 'selected' : ''}>${langData['scope_own_only'] || 'Own Only'}</option>
                    </select>
                    <select class="form-select form-select-sm perm-detail-level-select ${checked ? '' : 'd-none'}" style="width:auto;" data-role-id="${r.id}" data-permission-id="${p.id}">
                        <option value="full" ${detailLevel === 'full' ? 'selected' : ''}>${langData['detail_level_full'] || 'Full Detail'}</option>
                        <option value="summary" ${detailLevel === 'summary' ? 'selected' : ''}>${langData['detail_level_summary'] || 'Summary Only'}</option>
                    </select>`;
            }
            html += `</div></td>`;
        });
        html += `</tr>`;
    });
    html += `</tbody></table>`;
    $container.html(html);
    roles.forEach(r => updateRoleSelectAllState(r.id));
}

// Reflects each role column's own "select all" checkbox to match its permission cells IN THE
// CURRENTLY RENDERED MODULE TAB ONLY -- checked when every row is checked, indeterminate when some
// but not all are, unchecked when none are. Called after every render and after every individual
// cell toggle so the header checkbox never drifts out of sync with the rows underneath it.
function updateRoleSelectAllState(roleId) {
    const $cells = $(`#permissionMatrixContainer .perm-cell[data-role-id="${roleId}"]`);
    const $selectAll = $(`#permissionMatrixContainer .perm-role-select-all[data-role-id="${roleId}"]`);
    if (!$cells.length || !$selectAll.length) return;
    const checkedCount = $cells.filter(':checked').length;
    $selectAll.prop('checked', checkedCount === $cells.length);
    $selectAll.prop('indeterminate', checkedCount > 0 && checkedCount < $cells.length);
}
$(document).on('change', '.perm-role-select-all', function () {
    const roleId = $(this).data('roleId');
    const checked = this.checked;
    $(`#permissionMatrixContainer .perm-cell[data-role-id="${roleId}"]`).each(function () {
        if (this.checked !== checked) {
            this.checked = checked;
            $(this).trigger('change');
        }
    });
});

$(document).on('click', '#permissionModuleTabs .structure-menu', function () {
    const code = $(this).data('module');
    if (code === pmActiveModule) return;
    pmActiveModule = code;
    $('#permissionModuleTabs .structure-menu').removeClass('active');
    $(this).addClass('active');
    renderPermissionMatrixTable(pmActiveModule);
});

$(document).on('change', '.perm-cell', function () {
    const roleId = $(this).data('roleId');
    const permissionId = $(this).data('permissionId');
    const key = roleId + ':' + permissionId;
    const $cell = $(this).closest('td');
    const $scope = $cell.find('.perm-scope-select');
    const $detailLevel = $cell.find('.perm-detail-level-select');
    if (this.checked) {
        pmState[key] = {
            scope: $scope.length ? $scope.val() : 'all',
            detail_level: $detailLevel.length ? $detailLevel.val() : 'full',
        };
        $scope.removeClass('d-none');
        $detailLevel.removeClass('d-none');
    } else {
        delete pmState[key];
        $scope.addClass('d-none');
        $detailLevel.addClass('d-none');
    }
    updateRoleSelectAllState(roleId);
});

$(document).on('change', '.perm-scope-select', function () {
    const roleId = $(this).data('roleId');
    const permissionId = $(this).data('permissionId');
    const key = roleId + ':' + permissionId;
    if (pmState[key] !== undefined) {
        pmState[key].scope = $(this).val();
    }
});

$(document).on('change', '.perm-detail-level-select', function () {
    const roleId = $(this).data('roleId');
    const permissionId = $(this).data('permissionId');
    const key = roleId + ':' + permissionId;
    if (pmState[key] !== undefined) {
        pmState[key].detail_level = $(this).val();
    }
});

$(document).on('click', '#btnSavePermissionMatrix', function () {
    const $btn = $(this);
    const grants = Object.keys(pmState).map(key => {
        const [roleId, permissionId] = key.split(':');
        return { role_id: roleId, permission_id: permissionId, allow_scope: pmState[key].scope, detail_level: pmState[key].detail_level };
    });
    setButtonLoading($btn, true);
    $.ajax({
        url: `${BASE_URL}/api/permission-matrix.save`, method: 'POST', contentType: 'application/json',
        data: JSON.stringify({ grants }), dataType: 'json',
        success: function (res) {
            setButtonLoading($btn, false);
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                pmBaselineSnapshot = JSON.stringify(pmState);
            } else { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
        },
        error: function () { setButtonLoading($btn, false); showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
});
