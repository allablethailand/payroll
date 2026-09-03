/**
 * 2026-09-02, explicit request: "การเพิ่มแบบ Manual ตอนนี้เพิ่มได้แบบ 1 ต่อ 1 อยากให้เพิ่ม ให้เพิ่มได้ทีละ
 * หลายรายการ เป็นเหมือนหน้า Excel ในการจัดการ และเพิ่มให้ Import ได้ในหน้า Form นั้นเลย...การจัดการเหมือน
 * Excel แล้วกด Save ทีเดียว เป็น modal fullscreen ก็ได้ครับ" -- see modals.php's own #bulkEntryModal
 * comment for the full design rationale (why manual/import rows stay genuinely distinct in data_source
 * terms even though they share one grid).
 *
 * DOM-is-the-state: unlike a typical JS-model-driven grid, this does NOT keep a parallel row array in
 * sync with the inputs -- each <tr data-row-id> IS the source of truth, read directly at Save time.
 * Simpler and avoids an entire class of "UI shows X but state has Y" bugs for what's fundamentally a
 * short-lived, single-session editing surface (the grid is thrown away on modal close either way).
 *
 * Manual rows render the SAME field types (select2 Employee/Shift/Leave Type/OT Rate, native date/
 * time inputs, Status) the single-record attendanceModal/leaveModal/overtimeModal already use, and
 * save via the new .../bulk-save endpoints (data_source='manual'). Imported rows (from "Import File",
 * which reuses the EXISTING api/manual-import.preview as-is) render plain text inputs matching the
 * import pipeline's own employee_no/shift_code/leave_type_code/ot_rate_name text-code shape, and save
 * via the EXISTING api/manual-import.commit (data_source='import', a real sync_batches audit row) --
 * kept genuinely separate rather than force-converting codes to IDs, since text-code IS what commit()
 * expects and resolves server-side.
 */
const BULK_ENTRY_CONFIG = {
    attendance: {
        titleKey: 'attendance', titleFallback: 'Attendance', icon: 'fa-solid fa-clock',
        saveUrl: '/api/manual-attendance.bulk-save',
        dtVarName: 'dtAttendance',
        columns: [
            { labelKey: 'employee', labelFallback: 'Employee', required: true,
                manual: { field: 'employee_id', type: 'select2-remote', api: '/api/employee.report_to.get', apiType: '' },
                importField: 'employee_no' },
            { labelKey: 'work_date', labelFallback: 'Work Date', required: true,
                manual: { field: 'work_date', type: 'date' }, importField: 'work_date' },
            { labelKey: 'shift', labelFallback: 'Shift',
                manual: { field: 'shift_id', type: 'select2-remote', api: '/api/shift.options', apiType: 'shift' }, importField: 'shift_code' },
            { labelKey: 'clock_in', labelFallback: 'Clock In',
                manual: { field: 'clock_in', type: 'time' }, importField: 'clock_in' },
            { labelKey: 'clock_out', labelFallback: 'Clock Out',
                manual: { field: 'clock_out', type: 'time' }, importField: 'clock_out' },
            { labelKey: 'status', labelFallback: 'Status',
                manual: { field: 'status', type: 'select2-static', optionKeys: 'status_present,status_absent,status_leave,holiday', optionValues: 'present,absent,leave,holiday', default: 'present' },
                importField: 'status' },
        ],
        buildManualPayload: function ($row) {
            const workDate = $row.find('[data-field="work_date"]').val() || '';
            const clockIn = $row.find('[data-field="clock_in"]').val() || '';
            const clockOut = $row.find('[data-field="clock_out"]').val() || '';
            return {
                employee_id: parseInt($row.find('[data-field="employee_id"]').val()) || 0,
                work_date: workDate,
                shift_id: $row.find('[data-field="shift_id"]').val() || null,
                clock_in: clockIn ? `${workDate} ${clockIn}:00` : null,
                clock_out: clockOut ? `${workDate} ${clockOut}:00` : null,
                status: $row.find('[data-field="status"]').val() || 'present',
            };
        },
    },
    leave: {
        titleKey: 'leave', titleFallback: 'Leave', icon: 'fa-regular fa-calendar-check',
        saveUrl: '/api/manual-leave.bulk-save',
        dtVarName: 'dtLeave',
        columns: [
            { labelKey: 'employee', labelFallback: 'Employee', required: true,
                manual: { field: 'employee_id', type: 'select2-remote', api: '/api/employee.report_to.get', apiType: '' },
                importField: 'employee_no' },
            { labelKey: 'leave_type', labelFallback: 'Leave Type', required: true,
                manual: { field: 'leave_type_id', type: 'select2-remote', api: '/api/leave-type.options', apiType: 'leave_type' },
                importField: 'leave_type_code' },
            { labelKey: 'start_date', labelFallback: 'Start Date', required: true,
                manual: { field: 'start_date', type: 'date' }, importField: 'start_date' },
            { labelKey: 'end_date', labelFallback: 'End Date', required: true,
                manual: { field: 'end_date', type: 'date' }, importField: 'end_date' },
            { labelKey: 'total_days', labelFallback: 'Total Days', required: true,
                manual: { field: 'total_days', type: 'number', step: '0.5', min: '0.5' }, importField: 'total_days' },
            { labelKey: 'status', labelFallback: 'Status',
                manual: { field: 'status', type: 'select2-static', optionKeys: 'status_pending,status_approved,status_rejected,cancelled', optionValues: 'pending,approved,rejected,cancelled', default: 'approved' },
                importField: 'status' },
            { labelKey: 'reason', labelFallback: 'Reason',
                manual: { field: 'reason', type: 'text' }, importField: 'reason' },
        ],
        buildManualPayload: function ($row) {
            return {
                employee_id: parseInt($row.find('[data-field="employee_id"]').val()) || 0,
                leave_type_id: parseInt($row.find('[data-field="leave_type_id"]').val()) || 0,
                start_date: $row.find('[data-field="start_date"]').val() || '',
                end_date: $row.find('[data-field="end_date"]').val() || '',
                total_days: parseFloat($row.find('[data-field="total_days"]').val()) || 0,
                reason: ($row.find('[data-field="reason"]').val() || '').trim(),
                status: $row.find('[data-field="status"]').val() || 'approved',
            };
        },
    },
    overtime: {
        titleKey: 'overtime', titleFallback: 'Overtime', icon: 'fa-solid fa-stopwatch',
        saveUrl: '/api/manual-overtime.bulk-save',
        dtVarName: 'dtOvertime',
        columns: [
            { labelKey: 'employee', labelFallback: 'Employee', required: true,
                manual: { field: 'employee_id', type: 'select2-remote', api: '/api/employee.report_to.get', apiType: '' },
                importField: 'employee_no' },
            { labelKey: 'ot_rate', labelFallback: 'OT Rate', required: true,
                manual: { field: 'ot_rate_id', type: 'select2-remote', api: '/api/ot-rate.options', apiType: 'ot_rate' },
                importField: 'ot_rate_name' },
            { labelKey: 'ot_date', labelFallback: 'OT Date', required: true,
                manual: { field: 'ot_date', type: 'date' }, importField: 'ot_date' },
            { labelKey: 'hours', labelFallback: 'Hours', required: true,
                manual: { field: 'hours', type: 'number', step: '0.5', min: '0.5' }, importField: 'hours' },
            { labelKey: 'amount', labelFallback: 'Amount',
                manual: { field: 'amount', type: 'number', step: '0.01', min: '0' }, importField: 'amount' },
            { labelKey: 'status', labelFallback: 'Status',
                manual: { field: 'status', type: 'select2-static', optionKeys: 'status_pending,status_approved,status_rejected', optionValues: 'pending,approved,rejected', default: 'approved' },
                importField: 'status' },
        ],
        buildManualPayload: function ($row) {
            const amountVal = $row.find('[data-field="amount"]').val();
            return {
                employee_id: parseInt($row.find('[data-field="employee_id"]').val()) || 0,
                ot_rate_id: parseInt($row.find('[data-field="ot_rate_id"]').val()) || 0,
                ot_date: $row.find('[data-field="ot_date"]').val() || '',
                hours: parseFloat($row.find('[data-field="hours"]').val()) || 0,
                amount: amountVal !== '' && amountVal !== undefined ? parseFloat(amountVal) : null,
                status: $row.find('[data-field="status"]').val() || 'approved',
            };
        },
    },
};

let bulkEntryEntityType = null;
let bulkEntryRowSeq = 0;

function bulkEntryColLabel(col) {
    return langData[col.labelKey] || col.labelFallback;
}

/** @param {boolean} [opts.skipInitialRow] -- true when called from the "Load into Grid to Edit"
 *  path in the import modal, which is about to append REAL imported rows itself right after this
 *  returns -- an extra blank manual row there would just be noise, not a helpful starting point. */
function openBulkEntryModal(entityType, opts) {
    const cfg = BULK_ENTRY_CONFIG[entityType];
    if (!cfg) return;
    bulkEntryEntityType = entityType;
    bulkEntryRowSeq = 0;
    $('#bulkEntryModalIcon').attr('class', cfg.icon + ' me-1');
    $('#bulkEntryModalTitle').text(`${langData['bulk_entry_add_multiple'] || 'Add Multiple'} - ${langData[cfg.titleKey] || cfg.titleFallback}`);
    bulkEntryRenderThead(cfg);
    $('#bulkEntryTbody').empty();
    bulkEntryUpdateEmptyState();
    bulkEntryUpdateSummary();
    new bootstrap.Modal(document.getElementById('bulkEntryModal')).show();
    // Starts with one blank row so opening the modal already looks like a ready-to-fill single row,
    // not an empty shell -- "+ Add Row" is for the 2nd row onward.
    if (!opts || !opts.skipInitialRow) { bulkEntryAddManualRow(); }
}

/** Reuses an ALREADY-open grid (for the SAME entity type) instead of resetting it -- used by the
 *  import modal's "Load into Grid to Edit" button, since "Import File" inside an in-progress grid
 *  session must not wipe out rows the admin already typed. Opens fresh (reset, no initial blank
 *  row -- real imported rows are about to be appended) when the grid wasn't already open for this
 *  entity, e.g. reached via the standalone top-level "Import" button instead. */
function openOrFocusBulkEntryGrid(entityType) {
    const modalEl = document.getElementById('bulkEntryModal');
    const alreadyOpenForSameEntity = modalEl.classList.contains('show') && bulkEntryEntityType === entityType;
    if (!alreadyOpenForSameEntity) {
        openBulkEntryModal(entityType, { skipInitialRow: true });
    }
}

// 2026-09-02, explicit request: "ปรับ Design ตรงตารางที่เพิ่มใหม่ ให้ดูเป็นเหมือน Design Excel มากขึ้นครับ"
// -- a genuine row-number gutter column (like Excel's own leftmost row headers), styled via CSS
// (.bulk-entry-grid-rownum, see style.css), not just wider grid lines/header shading.
function bulkEntryRenderThead(cfg) {
    let html = '<tr><th class="bulk-entry-grid-rownum"></th><th style="width:110px;" data-i18n="bulk_entry_source_col">Source</th>';
    cfg.columns.forEach(col => {
        html += `<th>${escapeHtmlMe(bulkEntryColLabel(col))}${col.required ? ' <span class="text-danger">*</span>' : ''}</th>`;
    });
    html += '<th style="width:48px;"></th></tr>';
    $('#bulkEntryThead').html(html);
}
/** Renumbers the gutter column's own 1..N labels -- called after every add/remove so it always
 *  reflects the CURRENT row order, same as a real spreadsheet. */
function bulkEntryRenumberRows() {
    $('#bulkEntryTbody tr').each(function (i) { $(this).find('.bulk-entry-grid-rownum').text(i + 1); });
}

function bulkEntryUpdateEmptyState() {
    const hasRows = $('#bulkEntryTbody tr').length > 0;
    $('#bulkEntryEmptyState').toggleClass('d-none', hasRows);
    $('#tb_bulk_entry').closest('.bulk-entry-grid-wrap').toggleClass('d-none', !hasRows);
    // Called at every single point rows get added/removed already (add/import/remove/save-all) --
    // piggybacking the row-number gutter's own renumbering here means every one of those call sites
    // gets it for free, instead of having to remember to call it separately at each of them.
    bulkEntryRenumberRows();
}

// 2026-09-02, explicit request: "มี Summary เป็น Card" -- was a single plain-text line; now 3 small
// .stat-card cards (the same reusable component Payroll Detail's own info cards already use), one
// each for Total/Manual/Imported.
function bulkEntrySummaryCardHtml(cls, icon, labelKey, labelFallback, value) {
    return `<div class="col-4">
        <div class="stat-card ${cls}">
            <div class="stat-card-icon"><i class="fa-solid ${icon}"></i></div>
            <div>
                <div class="stat-card-label">${langData[labelKey] || labelFallback}</div>
                <div class="stat-card-value">${value}</div>
            </div>
        </div>
    </div>`;
}
function bulkEntryUpdateSummary() {
    const total = $('#bulkEntryTbody tr').length;
    const manual = $('#bulkEntryTbody tr[data-source="manual"]').length;
    const imported = total - manual;
    $('#bulkEntrySummaryCards').html(
        bulkEntrySummaryCardHtml('stat-card-info', 'fa-table-list', 'total', 'Total', total) +
        bulkEntrySummaryCardHtml('stat-card-purple', 'fa-keyboard', 'bulk_entry_source_manual', 'Manual', manual) +
        bulkEntrySummaryCardHtml('stat-card-primary', 'fa-file-import', 'bulk_entry_source_import', 'Imported', imported)
    );
}

/* ---------- Manual row ---------- */
function bulkEntryManualCellHtml(col, rowId) {
    const m = col.manual;
    const attrs = `data-field="${m.field}" data-row-id="${rowId}"`;
    if (m.type === 'select2-remote') {
        return `<select class="form-select form-select-sm select2-remote" ${attrs} data-api="${m.api}" data-type="${m.apiType || ''}"></select>`;
    }
    if (m.type === 'select2-static') {
        return `<select class="form-select form-select-sm select2-static" ${attrs} data-option-keys="${m.optionKeys}" data-option-values="${m.optionValues}"></select>`;
    }
    if (m.type === 'date') {
        return `<input type="date" class="form-control form-control-sm" ${attrs}>`;
    }
    if (m.type === 'time') {
        return `<input type="time" class="form-control form-control-sm" ${attrs}>`;
    }
    if (m.type === 'number') {
        return `<input type="number" class="form-control form-control-sm" ${attrs} step="${m.step || '1'}" min="${m.min || '0'}">`;
    }
    return `<input type="text" class="form-control form-control-sm" ${attrs}>`;
}
function bulkEntryAddManualRow() {
    const cfg = BULK_ENTRY_CONFIG[bulkEntryEntityType];
    const rowId = ++bulkEntryRowSeq;
    let html = `<tr data-source="manual" data-row-id="${rowId}">
        <td class="bulk-entry-grid-rownum"></td>
        <td><span class="badge bg-secondary-subtle text-secondary" data-i18n="bulk_entry_source_manual">${langData['bulk_entry_source_manual'] || 'Manual'}</span></td>`;
    cfg.columns.forEach(col => { html += `<td>${bulkEntryManualCellHtml(col, rowId)}</td>`; });
    html += `<td class="text-center"><button type="button" class="btn btn-link text-danger p-0 btn-bulk-entry-remove-row" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-trash-can"></i></button></td></tr>`;
    const $tr = $(html);
    $tr.appendTo('#bulkEntryTbody');
    initSelect2($tr.find('.select2-remote'));
    initSelect2($tr.find('.select2-static'));
    // Static-select defaults (e.g. Status='present') -- select2-static only pre-selects a value when
    // options.selectedValue is passed explicitly (see input.js's own initSelect2()), so set it here
    // right after init rather than relying on the <select>'s own (nonexistent, dynamically-built)
    // <option> markup to carry a "selected" default.
    cfg.columns.forEach(col => {
        if (col.manual.type === 'select2-static' && col.manual.default) {
            $tr.find(`[data-field="${col.manual.field}"]`).val(col.manual.default).trigger('change.select2');
        }
    });
    bulkEntryUpdateEmptyState();
    bulkEntryUpdateSummary();
}

// 2026-09-03, Manual Entry Phase 1A: same auto-fill/scoping logic as the single-entry modals
// (index.js's own #attendanceEmployee/#overtimeEmployee change handlers), applied generically per
// bulk-grid row since every row's employee picker shares the same `[data-field="employee_id"]`
// selector -- scoped to THIS row only via .closest('tr') so picking an employee in one row never
// touches another. No isLoadingManualEntryModal-style guard needed here (unlike the single-entry
// modals): a bulk row is always a brand-new manual entry, there's no "load an existing record for
// edit" path in this grid to race against.
$(document).on('change', '[data-field="employee_id"]', function () {
    const $row = $(this).closest('tr');
    if ($row.data('source') !== 'manual') return;
    const employeeId = $(this).val();
    const $shift = $row.find('[data-field="shift_id"]');
    if ($shift.length) {
        if (!employeeId) {
            $shift.empty().trigger('change.select2');
        } else {
            $.ajax({
                url: `${BASE_URL}/api/manual-entry.employee-context`, method: 'GET', data: { employee_id: employeeId }, dataType: 'json',
                success: function (res) {
                    if (res.status && res.data && res.data.shift_id) {
                        const label = (currentLang === 'th' ? res.data.shift_name_th : res.data.shift_name_en) || '';
                        $shift.empty().append(new Option(label, res.data.shift_id, true, true)).trigger('change.select2');
                    } else {
                        $shift.empty().trigger('change.select2');
                    }
                }
            });
        }
    }
    const $otRate = $row.find('[data-field="ot_rate_id"]');
    if ($otRate.length) {
        if (employeeId) { $otRate.attr('data-employee-id', employeeId); } else { $otRate.removeAttr('data-employee-id'); }
        $otRate.empty().trigger('change.select2');
    }
});

/* ---------- Imported rows ---------- */
function bulkEntryImportCellHtml(col, rowId, value) {
    return `<input type="text" class="form-control form-control-sm" data-field="${col.importField}" data-row-id="${rowId}" value="${escapeHtmlMe(value ?? '')}">`;
}
function bulkEntryAddImportRow(mappedRow, rowResult) {
    const cfg = BULK_ENTRY_CONFIG[bulkEntryEntityType];
    const rowId = ++bulkEntryRowSeq;
    const isError = rowResult && rowResult.status === 'error';
    const badgeCls = isError ? 'bg-danger-subtle text-danger' : 'bg-info-subtle text-info';
    const badgeIcon = isError ? 'fa-triangle-exclamation' : 'fa-file-import';
    const badgeTitle = rowResult && rowResult.message ? ` title="${escapeHtmlMe(rowResult.message)}"` : '';
    let html = `<tr data-source="import" data-row-id="${rowId}">
        <td class="bulk-entry-grid-rownum"></td>
        <td><span class="badge ${badgeCls}"${badgeTitle}><i class="fa-solid ${badgeIcon} me-1"></i>${langData['bulk_entry_source_import'] || 'Imported'}</span></td>`;
    cfg.columns.forEach(col => { html += `<td>${bulkEntryImportCellHtml(col, rowId, mappedRow[col.importField])}</td>`; });
    html += `<td class="text-center"><button type="button" class="btn btn-link text-danger p-0 btn-bulk-entry-remove-row" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-trash-can"></i></button></td></tr>`;
    $(html).appendTo('#bulkEntryTbody');
}

$(document).on('click', '#btnBulkEntryAddRow', function () { bulkEntryAddManualRow(); });
$(document).on('click', '.btn-bulk-entry-remove-row', function () {
    $(this).closest('tr').remove();
    bulkEntryUpdateEmptyState();
    bulkEntryUpdateSummary();
});
// 2026-09-02, explicit follow-up request -- "Import File" no longer silently triggers a hidden file
// input; it opens the new #bulkImportModal wizard (see the "IMPORT MODAL" section below), stacked on
// top of this grid modal (Bootstrap 5 handles nested modals natively, same precedent this app
// already uses elsewhere -- see CLAUDE.md's own note on Employment Certificate Template's Text/Image
// Library modals opening from inside its own Edit modal).
$(document).on('click', '#btnBulkEntryImport', function () { openBulkImportModal(bulkEntryEntityType); });

/* ---------- Save All ---------- */
function bulkEntrySaveAll() {
    const cfg = BULK_ENTRY_CONFIG[bulkEntryEntityType];
    const $rows = $('#bulkEntryTbody tr');
    if (!$rows.length) { return; }
    $('#bulkEntryTbody tr').removeClass('bulk-entry-row-error');
    const manualRows = []; // { $tr, payload }
    const importRows = []; // { $tr, payload }
    $rows.each(function () {
        const $tr = $(this);
        if ($tr.data('source') === 'manual') {
            manualRows.push({ $tr, payload: cfg.buildManualPayload($tr) });
        } else {
            const payload = {};
            cfg.columns.forEach(col => { payload[col.importField] = $tr.find(`[data-field="${col.importField}"]`).val(); });
            importRows.push({ $tr, payload });
        }
    });

    const $btn = $('#btnBulkEntrySaveAll');
    setButtonLoading($btn, true);
    const tasks = [];
    let manualResult = null;
    let importResult = null;
    if (manualRows.length) {
        tasks.push($.ajax({
            url: `${BASE_URL}${cfg.saveUrl}`, method: 'POST', contentType: 'application/json',
            data: JSON.stringify({ rows: manualRows.map(r => r.payload) }), dataType: 'json',
        }).done(function (res) { manualResult = res; }));
    }
    if (importRows.length) {
        tasks.push($.ajax({
            url: `${BASE_URL}/api/manual-import.commit`, method: 'POST',
            data: { entity_type: bulkEntryEntityType, mapped_rows: JSON.stringify(importRows.map(r => r.payload)),
                stored_file_token: bulkImportLastStoredFileToken || '', stored_file_name: bulkImportLastStoredFileName || '' }, dataType: 'json',
        }).done(function (res) { importResult = res; }));
    }

    $.when.apply($, tasks).always(function () {
        setButtonLoading($btn, false);
        let succeeded = 0;
        let failed = 0;
        // Manual: remove each succeeded row, mark each failed row with its own message.
        if (manualResult && manualResult.results) {
            manualResult.results.forEach((r, i) => {
                const $tr = manualRows[i].$tr;
                if (r.status) { succeeded++; $tr.remove(); }
                else { failed++; bulkEntryMarkRowFailed($tr, r.message); }
            });
        } else if (manualRows.length) {
            // The whole call itself failed (network/500) -- leave every manual row in place, flagged.
            manualRows.forEach(r => { failed++; bulkEntryMarkRowFailed(r.$tr, (manualResult && manualResult.message) || langData['save_failed'] || 'An error occurred while saving.'); });
        }
        // Import: row_results is positional against the SAME mapped_rows array just sent.
        if (importResult && importResult.row_results) {
            importResult.row_results.forEach((r, i) => {
                const $tr = importRows[i].$tr;
                if (r.status === 'ok') { succeeded++; $tr.remove(); }
                else { failed++; bulkEntryMarkRowFailed($tr, r.message || langData['save_failed'] || 'An error occurred while saving.'); }
            });
        } else if (importRows.length) {
            importRows.forEach(r => { failed++; bulkEntryMarkRowFailed(r.$tr, (importResult && importResult.message) || langData['save_failed'] || 'An error occurred while saving.'); });
        }

        bulkEntryUpdateEmptyState();
        bulkEntryUpdateSummary();
        const dt = window[cfg.dtVarName];
        if (dt) { dt.ajax.reload(null, false); }
        if (typeof refreshImportBatchDetailIfOpen === 'function') { refreshImportBatchDetailIfOpen(bulkEntryEntityType); }

        if (succeeded > 0) {
            let msg = (langData['bulk_entry_save_result'] || '{succeeded} saved successfully.').replace('{succeeded}', succeeded);
            if (failed > 0) {
                msg += ' ' + (langData['bulk_entry_save_result_failed_suffix'] || '{failed} failed -- see the highlighted row(s).').replace('{failed}', failed);
            }
            showSuccess(msg);
        } else if (failed > 0) {
            showWarning(langData['bulk_entry_save_all_failed'] || 'None of the rows could be saved -- see the highlighted row(s) for details.');
        }
        // Close only once the grid is genuinely empty (every row succeeded) -- a partial failure
        // keeps the modal open with just the failed rows left, ready to fix and retry.
        if ($('#bulkEntryTbody tr').length === 0) {
            const modalInstance = bootstrap.Modal.getInstance(document.getElementById('bulkEntryModal'));
            if (modalInstance) { modalInstance.hide(); }
        }
    });
}
function bulkEntryMarkRowFailed($tr, message) {
    $tr.addClass('bulk-entry-row-error');
    $tr.find('.bulk-entry-grid-rownum').next('td').find('.badge').attr('title', message || '');
    $tr.attr('title', message || '');
}
$(document).on('click', '#btnBulkEntrySaveAll', function () { bulkEntrySaveAll(); });

/* ==================== IMPORT MODAL (2026-09-02) ====================
 * Explicit follow-up request: the plain "click Import, silently pick a file" flow got its own proper
 * 2-step wizard -- Attach (instructions + Download Template + file form) then Review (summary +
 * row-by-row preview) BEFORE anything is persisted, ending in a real choice: commit immediately
 * ("Save Directly") or hand the parsed rows to #bulkEntryModal's own editable grid instead ("Load
 * into Grid to Edit"). Backend is UNCHANGED -- still just api/manual-import.preview then either
 * api/manual-import.commit (Save Directly) or nothing at all server-side (Load to Grid just moves
 * the SAME preview response into the grid's own rows, saved later via the grid's normal Save All).
 * This is also what replaced the old standalone Import tab -- see manual-entry/index.php's own
 * removal comment. Reachable two ways: bulkEntryEntityType is already set (stacked on top of an
 * open #bulkEntryModal, via its own "Import File" button) OR passed in fresh (the new standalone
 * top-level "Import" button on each tab, grid not open at all yet).
 * ==================== */
let bulkImportEntityType = null;
let bulkImportLastMappedRows = null;
let bulkImportLastRowResults = null;
// Platform Hardening Phase 5C: the token importPreview() handed back for the original uploaded
// file, echoed into whichever commit call actually happens (Save Directly below, or the grid's own
// Save All in bulkEntrySaveAll()) so it lands on the resulting sync_batches row. Best-effort, not
// tracked per-row -- loading a SECOND file into an already-open grid before saving overwrites this
// with the newer file's own token, so only the most recently previewed file's original is kept; same
// "no guarantee, just a nice-to-have" precedent this app already accepts for orphaned re-uploads.
let bulkImportLastStoredFileToken = null;
let bulkImportLastStoredFileName = null;

function openBulkImportModal(entityType) {
    const cfg = BULK_ENTRY_CONFIG[entityType];
    if (!cfg) return;
    bulkImportEntityType = entityType;
    bulkImportLastMappedRows = null;
    bulkImportLastRowResults = null;
    $('#bulkImportModalTitle').text(`${langData['bulk_entry_import_file'] || 'Import File'} - ${langData[cfg.titleKey] || cfg.titleFallback}`);
    $('#bulkImportFileInput').val('');
    bulkImportUpdateDropzone();
    bulkImportShowAttachStep();
    new bootstrap.Modal(document.getElementById('bulkImportModal')).show();
}
// 2026-09-02, explicit request: "ปรับหน้าตา Form ให้ดูสวยขึ้นและใช้งานง่ายขึ้น" -- reflects the chosen
// file's name in the dropzone card itself (the real <input type="file"> is visually hidden, see
// modals.php's own #bulkImportDropzone markup comment) so picking a file gives visible confirmation
// instead of a silently-updated native control nobody's looking at.
function bulkImportUpdateDropzone() {
    const fileInput = document.getElementById('bulkImportFileInput');
    const hasFile = fileInput.files && fileInput.files.length > 0;
    $('#bulkImportDropzone').toggleClass('bulk-import-dropzone-filled', hasFile);
    $('#bulkImportDropzoneFilename').text(hasFile ? fileInput.files[0].name : '');
}
$(document).on('change', '#bulkImportFileInput', bulkImportUpdateDropzone);
// Real HTML5 drag-and-drop -- the dropzone's own hint text promises it ("...or drag it here"), so
// it has to actually work, not just look like a drop target. preventDefault() on dragover is
// required or the browser's default "open the file directly, navigating away from the page" drop
// behavior wins instead of firing our own 'drop' handler.
$(document).on('dragover', '#bulkImportDropzone', function (e) {
    e.preventDefault();
    e.stopPropagation();
    $(this).addClass('bulk-import-dropzone-filled');
});
$(document).on('dragleave', '#bulkImportDropzone', function (e) {
    e.preventDefault();
    e.stopPropagation();
    bulkImportUpdateDropzone();
});
$(document).on('drop', '#bulkImportDropzone', function (e) {
    e.preventDefault();
    e.stopPropagation();
    const files = e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files;
    if (files && files.length) {
        document.getElementById('bulkImportFileInput').files = files;
        bulkImportUpdateDropzone();
    } else {
        bulkImportUpdateDropzone();
    }
});
function bulkImportShowAttachStep() {
    $('#bulkImportAttachStep').removeClass('d-none');
    $('#bulkImportReviewStep').addClass('d-none');
    $('#bulkImportAttachFooter').removeClass('d-none');
    $('#bulkImportReviewFooter').addClass('d-none');
}
function bulkImportShowReviewStep() {
    $('#bulkImportAttachStep').addClass('d-none');
    $('#bulkImportReviewStep').removeClass('d-none');
    $('#bulkImportAttachFooter').addClass('d-none');
    $('#bulkImportReviewFooter').removeClass('d-none');
}

$(document).on('click', '#btnBulkImportDownloadTemplate', function () {
    if (!bulkImportEntityType) return;
    window.location.href = `${BASE_URL}/api/manual-import.template?entity_type=${encodeURIComponent(bulkImportEntityType)}`;
});
$(document).on('click', '#btnBulkImportViewHistory', function () {
    const importModal = bootstrap.Modal.getInstance(document.getElementById('bulkImportModal'));
    if (importModal) { importModal.hide(); }
    const gridModal = bootstrap.Modal.getInstance(document.getElementById('bulkEntryModal'));
    if (gridModal) { gridModal.hide(); }
    $('#import-history-tab').trigger('click');
});

$(document).on('click', '#btnBulkImportRunPreview', function () {
    const fileInput = document.getElementById('bulkImportFileInput');
    if (!fileInput.files || !fileInput.files.length) {
        showWarning(langData['import_select_file_first'] || 'Choose a data type and a file first.');
        return;
    }
    const formData = new FormData();
    formData.append('entity_type', bulkImportEntityType);
    formData.append('file', fileInput.files[0]);
    const $btn = $(this);
    setButtonLoading($btn, true);
    $.ajax({
        url: `${BASE_URL}/api/manual-import.preview`, method: 'POST', data: formData, processData: false, contentType: false, dataType: 'json',
        success: function (res) {
            setButtonLoading($btn, false);
            if (!res.status) { showWarning(res.message || langData['import_failed'] || 'Import preview failed.'); return; }
            if (!res.mapped_rows || !res.mapped_rows.length) {
                showWarning(langData['import_no_rows'] || 'The file has no data rows.');
                return;
            }
            bulkImportLastMappedRows = res.mapped_rows;
            bulkImportLastRowResults = (res.preview && res.preview.row_results) || [];
            bulkImportLastStoredFileToken = res.stored_file_token || null;
            bulkImportLastStoredFileName = res.stored_file_name || null;
            bulkImportRenderReview(res);
            bulkImportShowReviewStep();
        },
        error: function () { setButtonLoading($btn, false); showWarning(langData['import_failed'] || 'Import preview failed.'); }
    });
});
$(document).on('click', '#btnBulkImportBack', function () { bulkImportShowAttachStep(); });

/** Same summary CARDS (Total/Success/Error/Conflict) + row-by-row preview table the old standalone
 *  Import tab's own renderImportPreviewResults() used to build (index.js), just into this modal's
 *  own elements and as .stat-card cards instead of plain badges per this round's own "Summary เป็น
 *  Card" request -- reuses importRowStatusBadge()/importEntityLabel() (index.js, kept there since
 *  the History tab's own DataTable render still needs them too). */
function bulkImportRenderReview(res) {
    const p = res.preview;
    $('#bulkImportSummaryCards').html(
        bulkEntrySummaryCardHtml('stat-card-info', 'fa-table-list', 'total', 'Total', p.total) +
        bulkEntrySummaryCardHtml('stat-card-success', 'fa-check', 'success', 'Success', p.success) +
        bulkEntrySummaryCardHtml('stat-card-danger', 'fa-triangle-exclamation', 'error', 'Error', p.error) +
        bulkEntrySummaryCardHtml('stat-card-warning', 'fa-code-compare', 'conflict', 'Conflict', p.conflict || 0)
    );
    if (res.unmapped_headers && res.unmapped_headers.length > 0) {
        $('#bulkImportUnmappedAlert').removeClass('d-none').html(
            `<i class="fa-solid fa-triangle-exclamation me-1"></i>${langData['import_unmapped_headers_warning'] || 'These file columns could not be matched to the template and were ignored:'} ` +
            res.unmapped_headers.map(h => `<code>${escapeHtmlMe(h)}</code>`).join(', ')
        );
    } else {
        $('#bulkImportUnmappedAlert').addClass('d-none').empty();
    }
    const $tbody = $('#tb_bulk_import_preview tbody').empty();
    (p.row_results || []).forEach(r => {
        let message = r.message || '';
        if (r.source_conflict) {
            message = (langData['import_source_conflict_warning'] || 'Overwrites an existing record last touched by: {source}').replace('{source}', importEntityLabel('source_' + r.previous_source) || r.previous_source);
        }
        $tbody.append(`<tr>
            <td>${r.row}</td>
            <td>${importRowStatusBadge(r)}</td>
            <td>${escapeHtmlMe(r.action || '-')}</td>
            <td>${escapeHtmlMe(message)}</td>
        </tr>`);
    });
}

$(document).on('click', '#btnBulkImportSaveDirect', function () {
    if (!bulkImportLastMappedRows || !bulkImportLastMappedRows.length) return;
    const $btn = $(this);
    setButtonLoading($btn, true);
    $.ajax({
        url: `${BASE_URL}/api/manual-import.commit`, method: 'POST',
        data: { entity_type: bulkImportEntityType, mapped_rows: JSON.stringify(bulkImportLastMappedRows),
            stored_file_token: bulkImportLastStoredFileToken || '', stored_file_name: bulkImportLastStoredFileName || '' }, dataType: 'json',
        success: function (res) {
            setButtonLoading($btn, false);
            if (!res.status) { showWarning(res.message || langData['import_failed'] || 'Import failed.'); return; }
            showSuccess((langData['import_commit_success'] || '{success} imported, {error} failed.').replace('{success}', res.success).replace('{error}', res.error));
            const modalInstance = bootstrap.Modal.getInstance(document.getElementById('bulkImportModal'));
            if (modalInstance) { modalInstance.hide(); }
            if (typeof dtImportHistory !== 'undefined' && dtImportHistory) { dtImportHistory.ajax.reload(null, false); }
            const cfg = BULK_ENTRY_CONFIG[bulkImportEntityType];
            const dt = cfg && window[cfg.dtVarName];
            if (dt) { dt.ajax.reload(null, false); }
            if (typeof refreshImportBatchDetailIfOpen === 'function') { refreshImportBatchDetailIfOpen(bulkImportEntityType); }
        },
        error: function () { setButtonLoading($btn, false); showWarning(langData['import_failed'] || 'Import failed.'); }
    });
});
$(document).on('click', '#btnBulkImportLoadToGrid', function () {
    if (!bulkImportLastMappedRows || !bulkImportLastMappedRows.length) return;
    const modalInstance = bootstrap.Modal.getInstance(document.getElementById('bulkImportModal'));
    if (modalInstance) { modalInstance.hide(); }
    openOrFocusBulkEntryGrid(bulkImportEntityType);
    bulkImportLastMappedRows.forEach((row, i) => bulkEntryAddImportRow(row, bulkImportLastRowResults[i]));
    bulkEntryUpdateEmptyState();
    bulkEntryUpdateSummary();
});
