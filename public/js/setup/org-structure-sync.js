// "Sync from Origami" picker for Department/Position/Team (Organizational Structure tab,
// 2026-08-28) -- one shared modal for all 3 entity types (see OrgStructureSyncModel's own
// docblock), same review-first architecture as employee-sync.js/holiday-sync.js: fetch, split
// New vs. Already Exists via proper DataTables data/columns config, checkboxes drive a
// selected-ref-id Set, apply only what's ticked, everything logged. No filter row -- unlike
// Employee Sync, there's nothing to narrow by, so the modal fetches immediately on open.
let orgSyncCurrentEntityType = null;
let orgSyncSelectedRefIds = new Set();
let orgSyncLastNewRows = [];
let orgSyncLastExistingRows = [];

function orgSyncEscapeHtml(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}
function orgSyncItemName(row) {
    const name = currentLang === 'en' ? (row.name_en || row.name_th) : (row.name_th || row.name_en);
    return name || '-';
}
function orgSyncCheckboxCellHtml(refId) {
    return `<input type="checkbox" class="org-sync-row-check" data-ref-id="${refId}"${orgSyncSelectedRefIds.has(refId) ? ' checked' : ''}>`;
}
function orgSyncUpdateBadgeHtml(row) {
    return row.has_update
        ? `<span class="badge bg-warning-subtle text-warning"><i class="fa-solid fa-rotate me-1"></i>${langData['employee_sync_update_available'] || 'Update available'}</span>`
        : `<span class="badge bg-success-subtle text-success">${langData['employee_sync_up_to_date'] || 'Up to date'}</span>`;
}
// Same "prettier" identity-cell convention as employee-sync.js's esRenderEmployeeCell() --
// initial-letter avatar circle + name, so this reads like a real record row.
function orgSyncRenderItemCell(row, subtitle) {
    const name = orgSyncItemName(row);
    const letter = name.trim().charAt(0).toUpperCase() || '?';
    return `
        <div class="d-flex align-items-center gap-2 py-1">
            <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold text-white flex-shrink-0" style="width:32px;height:32px;font-size:.78rem;background-color:#FF9900;">${orgSyncEscapeHtml(letter)}</div>
            <div class="lh-sm">
                <div class="fw-semibold">${orgSyncEscapeHtml(name)}</div>
                ${subtitle ? `<div class="text-muted small">${orgSyncEscapeHtml(subtitle)}</div>` : ''}
            </div>
        </div>
    `;
}

function orgSyncResetModal() {
    orgSyncSelectedRefIds = new Set();
    orgSyncLastNewRows = [];
    orgSyncLastExistingRows = [];
    $('#orgStructureSyncNotConnected').addClass('d-none');
    $('#orgStructureSyncBody').addClass('d-none');
    $('#orgStructureSyncResultArea').addClass('d-none');
    $('#orgStructureSyncLoadingHint').removeClass('d-none');
    if ($.fn.DataTable.isDataTable('#tb_org_sync_new')) { $('#tb_org_sync_new').DataTable().clear().draw(); }
    if ($.fn.DataTable.isDataTable('#tb_org_sync_existing')) { $('#tb_org_sync_existing').DataTable().clear().draw(); }
    $('#orgSyncNewCount, #orgSyncExistingCount, #orgSyncSelectedCount').text('0');
    $('#orgSyncSelectedCountLabel').text('');
    $('#btnApplyOrgStructureSync').addClass('d-none');
    $('#orgSyncNewSelectAll, #orgSyncExistingSelectAll').prop('checked', false);
}

function orgSyncUpdateSelectedCount() {
    const n = orgSyncSelectedRefIds.size;
    $('#orgSyncSelectedCount').text(n);
    $('#btnApplyOrgStructureSync').toggleClass('d-none', n === 0);
    $('#orgSyncSelectedCountLabel').text(n > 0 ? `${n} ${langData['employee_sync_selected_suffix'] || 'selected'}` : '');
}

function orgSyncRenderTables() {
    $('#tb_org_sync_new').DataTable({
        destroy: true, responsive: true, paging: false, info: false, searching: false, ordering: false,
        data: orgSyncLastNewRows,
        language: { emptyTable: langData['employee_sync_no_candidates'] || 'No candidates found.' },
        columns: [
            { data: 'ref_id', orderable: false, className: 'text-center', render: d => orgSyncCheckboxCellHtml(d) },
            { data: null, render: (d, t, row) => orgSyncRenderItemCell(row) },
        ],
    });
    $('#tb_org_sync_existing').DataTable({
        destroy: true, responsive: true, paging: false, info: false, searching: false, ordering: false,
        data: orgSyncLastExistingRows,
        language: { emptyTable: langData['employee_sync_no_candidates'] || 'No candidates found.' },
        columns: [
            { data: 'ref_id', orderable: false, className: 'text-center', render: d => orgSyncCheckboxCellHtml(d) },
            { data: null, render: (d, t, row) => orgSyncRenderItemCell(row, row.existing_code) },
            { data: null, render: (d, t, row) => orgSyncUpdateBadgeHtml(row) },
        ],
    });
}

function orgSyncFetchCandidates() {
    $.ajax({
        url: `${BASE_URL}/api/org-structure-sync.candidates`,
        method: 'POST',
        data: { entity_type: orgSyncCurrentEntityType },
        dataType: 'json',
        success: function (res) {
            $('#orgStructureSyncLoadingHint').addClass('d-none');
            if (res && res.not_connected) {
                $('#orgStructureSyncNotConnectedMessage').text(res.message || langData['employee_sync_not_connected_message'] || 'The connection to Origami has not been configured yet. Please contact your system administrator.');
                $('#orgStructureSyncNotConnected').removeClass('d-none');
                $('#orgStructureSyncBody').addClass('d-none');
                return;
            }
            $('#orgStructureSyncBody').removeClass('d-none');
            if (!res || !res.status) {
                showWarning((res && res.message) || langData['employee_sync_fetch_failed'] || 'Failed to fetch candidates.');
                return;
            }
            orgSyncSelectedRefIds = new Set();
            orgSyncLastNewRows = res.new || [];
            orgSyncLastExistingRows = res.existing || [];
            $('#orgStructureSyncResultArea').removeClass('d-none');
            orgSyncRenderTables();
            $('#orgSyncNewCount').text(orgSyncLastNewRows.length);
            $('#orgSyncExistingCount').text(orgSyncLastExistingRows.length);
            $('#orgSyncNewSelectAll, #orgSyncExistingSelectAll').prop('checked', false);
            orgSyncUpdateSelectedCount();
        },
        error: function () {
            $('#orgStructureSyncLoadingHint').addClass('d-none');
            $('#orgStructureSyncBody').removeClass('d-none');
            showWarning(langData['employee_sync_fetch_failed'] || 'Failed to fetch candidates.');
        }
    });
}

// 2026-08-30 (T007), real bug found and fixed (modal title audit) -- `orgSyncCurrentEntityType`
// is already tracked module-level state (department/position/team), so this title is re-derivable
// on a language change without needing to re-open the modal, same "remember + re-run" pattern as
// employee/detail.js's own eedModalTitle() fix.
function orgSyncRenderModalTitle() {
    const typeLabel = langData[orgSyncCurrentEntityType] || orgSyncCurrentEntityType;
    $('#orgStructureSyncModalLabelText').text(`${langData['employee_sync_button'] || 'Sync from Origami'} — ${typeLabel}`);
}
function orgSyncRefreshModalTitleLanguage() {
    if (orgSyncCurrentEntityType && $('#orgStructureSyncModal').hasClass('show')) {
        orgSyncRenderModalTitle();
    }
    if (orgSyncCurrentEntityType && $('#orgStructureSyncLogModal').hasClass('show')) {
        orgSyncRenderLogModalTitle();
    }
}
$(document).on('click', '.btn-open-org-sync', function () {
    orgSyncCurrentEntityType = $(this).data('entity-type');
    orgSyncResetModal();
    orgSyncRenderModalTitle();
    new bootstrap.Modal(document.getElementById('orgStructureSyncModal')).show();
    orgSyncFetchCandidates();
});

$(document).on('change', '.org-sync-row-check', function () {
    const refId = parseInt($(this).data('ref-id'), 10);
    if ($(this).is(':checked')) { orgSyncSelectedRefIds.add(refId); } else { orgSyncSelectedRefIds.delete(refId); }
    orgSyncUpdateSelectedCount();
});
$(document).on('change', '#orgSyncNewSelectAll', function () {
    const checked = $(this).is(':checked');
    orgSyncLastNewRows.forEach(row => { if (checked) orgSyncSelectedRefIds.add(row.ref_id); else orgSyncSelectedRefIds.delete(row.ref_id); });
    $('#tb_org_sync_new .org-sync-row-check').prop('checked', checked);
    orgSyncUpdateSelectedCount();
});
$(document).on('change', '#orgSyncExistingSelectAll', function () {
    const checked = $(this).is(':checked');
    orgSyncLastExistingRows.forEach(row => { if (checked) orgSyncSelectedRefIds.add(row.ref_id); else orgSyncSelectedRefIds.delete(row.ref_id); });
    $('#tb_org_sync_existing .org-sync-row-check').prop('checked', checked);
    orgSyncUpdateSelectedCount();
});

$(document).on('click', '#btnApplyOrgStructureSync', function () {
    const refIds = Array.from(orgSyncSelectedRefIds);
    if (!refIds.length) return;
    showConfirm(
        langData['employee_sync_confirm_title'] || 'Sync selected records?',
        (langData['org_structure_sync_confirm_message'] || 'This will insert/update {count} record(s) in this system.').replace('{count}', refIds.length),
        function () {
            const $btn = $('#btnApplyOrgStructureSync').prop('disabled', true);
            $.ajax({
                url: `${BASE_URL}/api/org-structure-sync.apply`,
                method: 'POST',
                data: { entity_type: orgSyncCurrentEntityType, ref_ids: refIds },
                dataType: 'json',
                success: function (res) {
                    $btn.prop('disabled', false);
                    if (!res || !res.status) {
                        showWarning((res && res.message) || langData['employee_sync_apply_failed'] || 'Failed to sync selected records.');
                        return;
                    }
                    const tpl = langData['employee_sync_result_message'] || 'Synced {success} of {total} record(s).{errors}';
                    const errorsMsg = res.error > 0 ? ` ${res.error} ${langData['employee_sync_result_failed_suffix'] || 'failed.'}` : '';
                    showSuccess(tpl.replace('{success}', res.success).replace('{total}', res.total).replace('{errors}', errorsMsg));
                    // Reload whichever Organizational Structure table is currently on screen for
                    // this entity type -- structureTables[type] is company-profile.js's own global.
                    if (typeof structureTables !== 'undefined' && structureTables[orgSyncCurrentEntityType]) {
                        structureTables[orgSyncCurrentEntityType].ajax.reload(null, false);
                    }
                    orgSyncFetchCandidates();
                },
                error: function () {
                    $btn.prop('disabled', false);
                    showWarning(langData['employee_sync_apply_failed'] || 'Failed to sync selected records.');
                }
            });
        }
    );
});

function orgSyncStatusBadge(status) {
    const map = { completed: 'bg-success-subtle text-success', running: 'bg-warning-subtle text-warning', failed: 'bg-danger-subtle text-danger' };
    const cls = map[status] || 'bg-light text-dark';
    const text = langData['sync_log_status_' + status] || status;
    return `<span class="badge ${cls}">${text}</span>`;
}

function orgSyncRenderLogModalTitle() {
    const typeLabel = langData[orgSyncCurrentEntityType] || orgSyncCurrentEntityType;
    $('#orgStructureSyncLogModalLabelText').text(`${langData['employee_sync_log_button'] || 'Sync Log'} — ${typeLabel}`);
}
$(document).on('click', '.btn-open-org-sync-log', function () {
    orgSyncCurrentEntityType = $(this).data('entity-type');
    orgSyncRenderLogModalTitle();
    new bootstrap.Modal(document.getElementById('orgStructureSyncLogModal')).show();
    $('#tb_org_structure_sync_log tbody').html(`<tr><td colspan="6" class="text-center text-muted py-3"><i class="fa-solid fa-spinner fa-spin me-1"></i>${langData['loading'] || 'Loading...'}</td></tr>`);
    $.ajax({
        url: `${BASE_URL}/api/org-structure-sync.log`,
        method: 'GET',
        data: { entity_type: orgSyncCurrentEntityType },
        dataType: 'json',
        success: function (res) {
            const rows = (res && res.status) ? (res.data || []) : [];
            if (!rows.length) {
                $('#tb_org_structure_sync_log tbody').html(`<tr><td colspan="6" class="text-center text-muted py-3">${langData['employee_sync_log_empty'] || 'No sync history yet.'}</td></tr>`);
                return;
            }
            $('#tb_org_structure_sync_log tbody').html(rows.map(function (r) {
                const byName = (currentLang === 'th' ? r.triggered_by_name_th : r.triggered_by_name_en) || r.triggered_by_name_th || r.triggered_by_name_en || '-';
                const dateStr = typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(r.started_at) : r.started_at;
                return `
                    <tr>
                        <td>${orgSyncEscapeHtml(dateStr)}</td>
                        <td>${orgSyncEscapeHtml(byName)}</td>
                        <td>${orgSyncStatusBadge(r.status)}</td>
                        <td class="text-end">${orgSyncEscapeHtml(r.total_count)}</td>
                        <td class="text-end text-success">${orgSyncEscapeHtml(r.success_count)}</td>
                        <td class="text-end ${Number(r.error_count) > 0 ? 'text-danger' : ''}">${orgSyncEscapeHtml(r.error_count)}</td>
                    </tr>
                `;
            }).join(''));
        },
        error: function () {
            $('#tb_org_structure_sync_log tbody').html(`<tr><td colspan="6" class="text-center text-danger py-3">${langData['employee_sync_fetch_failed'] || 'Failed to load.'}</td></tr>`);
        }
    });
});
