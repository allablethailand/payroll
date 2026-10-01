// design:clean -- docs/design/rules.md §12. Passes scripts/check-design.php with 0 hits.
/**
 * Data Import page (/imports): upload -> match columns -> check data (fix + re-validate) -> import.
 * Talks only to ImportController's api/import.* endpoints; every rule about what may be imported is
 * enforced server-side, this file only drives the steps and renders the staged rows.
 */
let impBatch = null;          // { id, entity_type, columns, fileHeaders, samples, mapping }
let impStep = 'map';          // 'map' | 'verify'
let impEdits = {};            // row_no -> { field key: value } typed in the grid, not yet sent
let impRowsTable = null;
let impLogTable = null;
let impBusy = false;

function impT(key, fallback) {
    return (langData && langData[key]) || fallback;
}

function impPost(url, body) {
    return $.ajax({ url: `${BASE_URL}/api/${url}`, method: 'POST', contentType: 'application/json', data: JSON.stringify(body), dataType: 'json' });
}

function impFail(res) {
    showError((res && res.message) || impT('import_error_generic', 'Something went wrong. Please try again.'));
}

function impSetBusy(busy) {
    impBusy = busy;
    $('#impPrimary, #impDiscard, #impRevalidate').prop('disabled', busy);
    if (!busy) impSyncVerifyControls();
}

/* ---------- Step 1: upload ---------- */

$('#impUploadForm').on('submit', function (e) {
    e.preventDefault();
    const file = $('#impFile')[0].files[0];
    if (!file) { showError(impT('import_choose_file_first', 'Choose a file first.')); return; }
    const fd = new FormData();
    fd.append('entity_type', $('#impEntityType').val());
    fd.append('file', file);
    const $btn = $('#impUploadBtn').prop('disabled', true);
    $.ajax({ url: `${BASE_URL}/api/import.upload`, method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
        .done(function (res) {
            if (!res.status) { impFail(res); return; }
            impBatch = { id: res.batch_id, entity_type: $('#impEntityType').val(), columns: res.columns, fileHeaders: res.file_headers, samples: res.samples || {}, mapping: res.suggested_mapping || {}, total: res.total };
            impEdits = {};
            impRenderMapping();
            impShowStep('map');
            bootstrap.Modal.getOrCreateInstance('#impWizardModal').show();
        })
        .fail(() => impFail())
        .always(() => $btn.prop('disabled', false));
});

$('#impDownloadTemplate').on('click', function () {
    window.location.href = `${BASE_URL}/api/import.template?entity_type=${encodeURIComponent($('#impEntityType').val())}`;
});

/* ---------- Step 1: mapping ---------- */

function impRenderMapping() {
    const options = [`<option value="">${escapeAttr(impT('import_skip_column', 'Do not import'))}</option>`]
        .concat(Object.entries(impBatch.columns).map(([key, label]) => `<option value="${escapeAttr(key)}">${escapeAttr(label)}</option>`)).join('');
    const rows = impBatch.fileHeaders.map(function (h) {
        return `<tr>
            <td>${escapeAttr(h)}</td>
            <td class="text-muted">${escapeAttr(impBatch.samples[h] || '')}</td>
            <td><select class="form-select imp-map-select" data-header="${escapeAttr(h)}">${options}</select></td>
        </tr>`;
    });
    $('#tb_import_mapping tbody').html(rows.join(''));
    $('#tb_import_mapping .imp-map-select').each(function () {
        $(this).val(impBatch.mapping[$(this).data('header')] || '');
    });
    initSelect2('.imp-map-select', { mode: 'native', searchable: false });
}

function impCollectMapping() {
    const mapping = {};
    $('#tb_import_mapping .imp-map-select').each(function () {
        const v = $(this).val();
        if (v) mapping[$(this).data('header')] = v;
    });
    return mapping;
}

function impMapAndValidate() {
    const mapping = impCollectMapping();
    const targets = Object.values(mapping);
    if (!targets.length) { showError(impT('import_map_at_least_one', 'Match at least one column.')); return; }
    if (new Set(targets).size !== targets.length) { showError(impT('import_map_duplicate', 'Each field can be matched to only one column.')); return; }
    impSetBusy(true);
    impPost('import.map', { batch_id: impBatch.id, mapping })
        .then(res => res.status ? impPost('import.validate', { batch_id: impBatch.id }) : $.Deferred().reject(res))
        .done(function (res) {
            if (!res.status) { impFail(res); return; }
            impBatch.mapping = mapping;
            impShowStep('verify');
            impRenderStats(res.batch);
            impBuildRowsTable();
        })
        .fail(res => impFail(res && res.responseJSON ? res.responseJSON : res))
        .always(() => impSetBusy(false));
}

/* ---------- Step 2: verification ---------- */

function impShowStep(step) {
    impStep = step;
    $('#impStepMap').toggleClass('d-none', step !== 'map');
    $('#impStepVerify').toggleClass('d-none', step !== 'verify');
    $('#impPrimary').attr('data-i18n', step === 'map' ? 'import_continue' : 'import_confirm')
        .text(step === 'map' ? impT('import_continue', 'Continue') : impT('import_confirm', 'Confirm Import'));
    impSyncVerifyControls();
}

function impRenderStats(b) {
    impBatch.summary = b;
    $('#impStatTotal').text(fmtNum(b.total, 0, 3));
    $('#impStatValid').text(fmtNum(b.valid, 0, 3));
    $('#impStatWarnings').text(fmtNum(b.warnings, 0, 3));
    $('#impStatErrors').text(fmtNum(b.errors, 0, 3));
    impSyncVerifyControls();
}

function impEditedCount() {
    return Object.keys(impEdits).length;
}

function impSyncVerifyControls() {
    if (impStep !== 'verify' || !impBatch || !impBatch.summary) return;
    const edited = impEditedCount();
    $('#impEditedHint').text(edited ? impT('import_edited_hint', '{n} edited rows').replace('{n}', edited) : '');
    $('#impRevalidate').prop('disabled', impBusy || edited === 0);
    $('#impPrimary').prop('disabled', impBusy || edited > 0 || impBatch.summary.errors > 0);
}

function impBuildRowsTable() {
    if (impRowsTable) { impRowsTable.destroy(); impRowsTable = null; }
    const labels = impBatch.columns; // captured: a late redraw after the wizard closed must not read the (now null) impBatch
    const keys = Object.keys(labels).filter(k => Object.values(impBatch.mapping).includes(k));
    $('#tb_import_rows').empty().append(
        `<thead><tr><th><span>#</span></th><th><span data-i18n="status">Status</span></th>${keys.map(k => `<th><span>${escapeAttr(labels[k])}</span></th>`).join('')}<th><span data-i18n="import_col_messages">Messages</span></th></tr></thead><tbody></tbody>`);
    const columns = [{ data: 'row_no', className: 'text-end' }, { data: 'status', render: s => statusBadgeHtml(s, 'import_row_status') }]
        .concat(keys.map(k => ({
            data: null, orderable: false,
            render: (d, t, row) => `<input type="text" class="form-control imp-cell" data-row="${row.row_no}" data-field="${escapeAttr(k)}" value="${escapeAttr((row.data && row.data[k]) ?? '')}" aria-label="${escapeAttr(labels[k])}">`,
        })))
        .concat([{ data: 'messages', orderable: false, render: m => escapeAttr((m || []).join(' ')) }]);
    impRowsTable = initSharedDataTable('#tb_import_rows', {
        serverSide: true,
        // Top-level status filter + typed cells replace per-column filters; row order is fixed (errors first).
        ajax: function (dtData, callback) {
            if (!impBatch) { callback({ draw: dtData.draw, recordsTotal: 0, recordsFiltered: 0, data: [] }); return; }
            $.getJSON(`${BASE_URL}/api/import.rows`, { batch_id: impBatch.id, status: $('#impRowStatus').val() === 'all' ? '' : $('#impRowStatus').val(), offset: dtData.start, limit: dtData.length })
                .done(res => callback({ draw: dtData.draw, recordsTotal: res.total || 0, recordsFiltered: res.total || 0, data: res.rows || [] }))
                .fail(() => callback({ draw: dtData.draw, recordsTotal: 0, recordsFiltered: 0, data: [] }));
        },
        dtOptions: { ordering: false, searching: false, pageLength: 50, columns },
    });
    updateText(document.getElementById('impStepVerify'));
}

$(document).on('input', '.imp-cell', function () {
    const row = $(this).data('row');
    (impEdits[row] = impEdits[row] || {})[$(this).data('field')] = $(this).val();
    impSyncVerifyControls();
});

$('#impRowStatus').on('change', function () {
    if (impRowsTable) impRowsTable.ajax.reload(null, true);
});

$('#impRevalidate').on('click', function () {
    const rowNos = Object.keys(impEdits).map(Number);
    if (!rowNos.length) return;
    impSetBusy(true);
    impPost('import.edit-rows', { batch_id: impBatch.id, edits: rowNos.map(n => ({ row_no: n, data: impEdits[n] })) })
        .then(res => res.status ? impPost('import.validate', { batch_id: impBatch.id, row_nos: rowNos }) : $.Deferred().reject(res))
        .done(function (res) {
            if (!res.status) { impFail(res); return; }
            impEdits = {};
            impRenderStats(res.batch);
            impRowsTable.ajax.reload(null, false);
        })
        .fail(res => impFail(res && res.responseJSON ? res.responseJSON : res))
        .always(() => impSetBusy(false));
});

/* ---------- commit / discard / close ---------- */

$('#impPrimary').on('click', function () {
    if (impStep === 'map') { impMapAndValidate(); return; }
    const n = impBatch.summary.valid + impBatch.summary.warnings;
    showConfirm({
        title: impT('import_confirm_title', 'Import {n} rows?').replace('{n}', fmtNum(n, 0, 3)),
        message: impT('import_confirm_message', 'The rows will be written to your records.'),
        confirmText: impT('import_confirm', 'Confirm Import'),
        cancelText: impT('cancel', 'Cancel'),
        onYes: function () {
            impSetBusy(true);
            impPost('import.commit', { batch_id: impBatch.id })
                .done(function (res) {
                    if (!res.status) { impFail(res); return; }
                    impEnd();
                    showSuccess(impT('import_done', 'Imported'));
                    if (impLogTable) impLogTable.ajax.reload(null, false);
                })
                .fail(res => impFail(res && res.responseJSON ? res.responseJSON : res))
                .always(() => impSetBusy(false));
        },
    });
});

function impEnd() {
    impBatch = null;
    impEdits = {};
    $('#impFile').val('');
    bootstrap.Modal.getOrCreateInstance('#impWizardModal').hide();
}

function impDiscardAndClose() {
    const id = impBatch && impBatch.id;
    impEnd();
    if (id) impPost('import.discard', { batch_id: id });
}

// Closing is discarding (a staged file cannot be resumed), so ask first only when typed edits would be lost.
function impRequestClose() {
    if (!impBatch) { impEnd(); return; }
    if (impEditedCount() === 0) { impDiscardAndClose(); return; }
    showConfirm({
        title: impT('import_discard_title', 'Discard this import?'),
        message: impT('import_discard_message', 'Your edits and the uploaded rows will be removed.'),
        tone: 'warning',
        confirmText: impT('import_discard', 'Discard'),
        cancelText: impT('import_keep_editing', 'Keep editing'),
        onYes: impDiscardAndClose,
    });
}

$('#impDiscard, #impWizardClose').on('click', impRequestClose);

/* ---------- Activity Log tab ---------- */

// Only a successful commit of an ad-hoc item import that has not been rolled back yet can be undone.
function impLogActionsHtml(row) {
    if (row.can_rollback) {
        return `<button type="button" class="btn btn-link btn-circle-action imp-rollback" data-batch="${Number(row.sync_batch_id)}" title="${escapeAttr(impT('import_rollback', 'Roll back'))}"><i class="fa-solid fa-rotate-left"></i></button>`;
    }
    return row.rolled_back ? escapeAttr(impT('import_rolled_back', 'Rolled back')) : '';
}

$(document).on('click', '.imp-rollback', function () {
    const syncBatchId = Number($(this).data('batch'));
    showConfirm({
        title: impT('import_rollback_title', 'Roll back this import?'),
        message: impT('import_rollback_message', 'Its lines are removed from the draft payroll runs and the runs are recalculated.'),
        tone: 'warning',
        confirmText: impT('import_rollback', 'Roll back'),
        cancelText: impT('cancel', 'Cancel'),
        onYes: function () {
            impPost('import.rollback', { sync_batch_id: syncBatchId })
                .done(function (res) {
                    if (!res.status) { impFail(res); return; }
                    showSuccess(impT('import_rollback_done', 'Rolled back'));
                    if (impLogTable) impLogTable.ajax.reload(null, false);
                })
                .fail(res => impFail(res && res.responseJSON ? res.responseJSON : res));
        },
    });
});

function impInitLogTable() {
    if (impLogTable) return;
    impLogTable = initSharedDataTable('#tb_import_log', {
        columnFilters: {
            mode: 'client',
            columns: [
                { index: 0, key: 'performed_at' }, { index: 1, key: 'by' }, { index: 2, key: 'entity_type' }, { index: 3, key: 'action' },
                { index: 4, key: 'outcome' }, { index: 5, key: 'file_name' }, { index: 6, key: 'total' }, { index: 7, key: 'failed' },
                { index: 8, key: 'ip_address' }, { index: 9, key: 'device' },
            ],
        },
        dtOptions: {
            searching: true,
            order: [[0, 'desc']],
            ajax: { url: `${BASE_URL}/api/import.activity-log`, dataSrc: json => (json && json.data) || [] },
            columns: [
                { data: 'performed_at', render: { display: d => formatDisplayDateTime(d), sort: d => d, filter: d => formatDisplayDateTime(d) } },
                { data: null, render: (d, t, row) => escapeAttr((currentLang === 'th' ? row.performed_by_name_th : row.performed_by_name_en) || '-') },
                { data: 'entity_type', render: d => escapeAttr(impT({ employee_import: 'import_entity_employees', ytd_opening: 'import_entity_ytd', adhoc_item: 'import_entity_adhoc', attendance_summary: 'import_entity_attendance_summary' }[d] || d, d)) },
                { data: 'action', render: d => escapeAttr(impT('import_action_' + d, d)) },
                { data: 'outcome', render: { display: d => statusBadgeHtml(d, 'import_outcome'), sort: d => d, filter: d => impT('import_outcome_' + d, d) } },
                { data: 'file_name', render: d => escapeAttr(d || '-') },
                { data: 'total', className: 'text-end', render: { display: d => d === null ? '-' : fmtNum(Number(d), 0, 3), sort: d => Number(d || 0), filter: d => d === null ? '-' : String(d) } },
                { data: 'failed', className: 'text-end', render: { display: d => d === null ? '-' : fmtNum(Number(d), 0, 3), sort: d => Number(d || 0), filter: d => d === null ? '-' : String(d) } },
                { data: 'ip_address', render: d => escapeAttr(d || '-') },
                { data: null, render: (d, t, row) => escapeAttr([row.browser, row.os].filter(Boolean).join(' / ') || '-') },
                { data: null, orderable: false, className: 'text-center', render: (d, t, row) => impLogActionsHtml(row) },
            ],
        },
    });
}

$('#imp-log-tab').on('shown.bs.tab', impInitLogTable);

$(function () {
    $('#phTitle').attr('data-i18n', 'data_import_menu').text(impT('data_import_menu', 'Data Import'));
    $('#phDescription').attr('data-i18n', 'data_import_description');
    initSelect2('#impEntityType', { mode: 'static' });
    initSelect2('#impRowStatus', { mode: 'static' });
    $('#impEntityType').val($('#impEntityType').data('optionValues').split(',')[0]).trigger('change');
    $('#impRowStatus').val('all').trigger('change');
});
