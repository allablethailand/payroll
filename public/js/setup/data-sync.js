/**
 * 2026-09-02, "Data Sync" page -- UI trigger for MasterDataSyncOrchestrator (department/position/
 * shift/branch/team/holiday). See MasterDataSyncController's own docblock for why 'employee' is
 * deliberately not one of the per-type cards here (its own bulk fetch is still an unimplemented
 * stub -- the real employee sync entry point is Employee List's own picker, unrelated to this page).
 *
 * 2026-09-02, same-day redesign (explicit request: "แยกเป็น 2 Tab คือ Tab ที่กด Sync และ Tab ประวัติการ
 * Sync...ต้องบอกด้วยว่า Sync ล่าสุดเมื่อไหร่ โดยใคร...กดแล้วให้มี Confirm แล้ว Sync เลย...อยากให้มี % บอกด้วย")
 * -- 2 tabs (Sync / Sync History, see data-sync.php's own .setup-tabs markup), every card's "last
 * synced" line now shows who too (SyncBatchModel::lastSyncTimes()'s own widened response), every
 * Sync button (per-card AND Sync All) confirms before running (no modal), and both run through the
 * SAME dsRunSyncSequence() helper so the progress bar reflects REAL step completion, not a faked
 * animation -- see that function's own docblock.
 *
 * Also fixes a real crash found in this same round (explicit bug report, RangeError: Maximum call
 * stack size exceeded, jquery.min.js / app.js's updateText / this file's own drawCallback) -- the
 * OLD version of this file called `applyLanguage($('#tb_data_sync_history')[0])` from inside that
 * table's own `drawCallback`. `applyLanguage(lang, root = document)` took the DOM element as `lang`
 * (never a valid language string) and silently defaulted `root` to the WHOLE document every time --
 * but the real damage is `applyLanguage()`'s own unconditional tail call to `refreshAllTables()` →
 * `refreshAllDataTablesLanguage()`, which calls `table.draw(false)` on EVERY DataTable on the page,
 * including this exact table, which re-fires `drawCallback`, which calls `applyLanguage()` again --
 * synchronous, unbounded self-recursion within one call stack (confirmed by reading
 * `refreshAllDataTablesLanguage()`'s own source, not guessed). Fixed at the root two ways: (1) this
 * file no longer calls `applyLanguage()`/`updateText()` from ANY drawCallback at all -- every cell
 * in this table is already rendered from `langData` directly inside its own `render` function (see
 * `dsEntityLabel()`/`dsStatusBadge()` below), so there was nothing genuinely needing a per-draw
 * `data-i18n` sweep in the first place; a real language SWITCH already re-sweeps the whole document
 * via the global `loadLang()`/`applyLanguage(lang)` flow, which covers this table's own static
 * `data-i18n` spans (e.g. the "View Errors" button) for free. (2) `refreshAllDataTablesLanguage()`
 * itself also got a re-entrancy guard in app.js as defense-in-depth, so this exact bug class can't
 * silently recur on some future page that makes the same drawCallback mistake.
 */

const DS_ENTITY_META = {
    department: { icon: 'fa-sitemap', labelKey: 'department' },
    position: { icon: 'fa-id-badge', labelKey: 'position' },
    shift: { icon: 'fa-clock', labelKey: 'shift' },
    branch: { icon: 'fa-code-branch', labelKey: 'branch' },
    team: { icon: 'fa-people-group', labelKey: 'team' },
    holiday: { icon: 'fa-calendar-day', labelKey: 'holiday' },
};
const DS_ENTITY_ORDER = ['department', 'position', 'shift', 'branch', 'team', 'holiday'];

let tb_data_sync_history;
let dsHistoryTableInited = false;

function dsEntityLabel(type) {
    const meta = DS_ENTITY_META[type];
    return (meta && langData[meta.labelKey]) || (meta ? meta.labelKey : type);
}

// 2026-09-02, explicit request: "ต้องบอกด้วยว่า Sync ล่าสุดเมื่อไหร่ โดยใคร" -- `entry` is one value from
// SyncBatchModel::lastSyncTimes()'s own widened response ({last_sync_at, triggered_by_name_*}) or
// undefined if this entity type has never completed a sync.
function dsLastSyncLine(entry) {
    if (!entry || !entry.last_sync_at) {
        return langData['data_sync_never'] || 'Never synced';
    }
    const when = typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(entry.last_sync_at) : entry.last_sync_at;
    const who = (currentLang === 'th' ? `${entry.triggered_by_name_th || ''} ${entry.triggered_by_surname_th || ''}` : `${entry.triggered_by_name_en || ''} ${entry.triggered_by_surname_en || ''}`).trim();
    if (!who) {
        return when;
    }
    const tpl = langData['data_sync_last_sync_by'] || '{when} by {who}';
    return tpl.replace('{when}', when).replace('{who}', who);
}

// 2026-09-07, explicit request: "อยากให้ปรับรูปแบบ Card ให้บอกด้วยว่า Sync ไปแล้วกี่ครั้ง ครั้งล่าสุดเมื่อไหร่
// และสามารถคลิกดู modal ประวัติการ Sync ของแต่ละ Card ได้" -- a small count badge next to each card's
// title (SyncBatchModel::lastSyncTimes()'s own new `sync_count` field, see that method's own
// docblock) plus a "History" button that opens #dsCardHistoryModal filtered to just this entity
// type (dsOpenCardHistory() below, reuses the SAME api/master-data-sync.history endpoint the
// Sync History tab's own table already calls -- no new backend endpoint needed).
function dsSyncCountBadge(count) {
    const n = Number(count) || 0;
    const tpl = langData['data_sync_count_badge'] || '{count}x';
    return `<span class="badge rounded-pill ds-sync-count-badge" title="${escapeAttr((langData['data_sync_count_title'] || 'Synced {count} times').replace('{count}', n))}">${escapeHtml(tpl.replace('{count}', n))}</span>`;
}
function dsRenderCards(statusData) {
    const lastSyncAt = statusData.last_sync_at || {};
    let html = '';
    DS_ENTITY_ORDER.forEach(function (type) {
        const meta = DS_ENTITY_META[type];
        const entry = lastSyncAt[type];
        html += `
            <div class="col-lg-4 col-md-6">
                <div class="settings-info-card h-100" data-ds-card="${type}">
                    <div class="settings-info-card-header">
                        <i class="fa-solid ${meta.icon}"></i>
                        <div>
                            <p class="settings-info-card-title mb-0 d-flex align-items-center gap-2">${escapeHtml(dsEntityLabel(type))}${dsSyncCountBadge(entry && entry.sync_count)}</p>
                            <p class="settings-info-card-desc mb-0" data-ds-last-sync="${type}">${escapeHtml(dsLastSyncLine(entry))}</p>
                        </div>
                    </div>
                    <div class="settings-info-card-body">
                        <div class="ds-progress-wrap d-none mb-2" data-ds-progress="${type}">
                            <div class="progress" style="height:6px;">
                                <div class="progress-bar" role="progressbar" style="width:0%; background-color:#FF9900;"></div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <button type="button" class="btn btn-link btn-sm p-0 ds-view-history-btn" data-entity-type="${type}">
                                <i class="fa-solid fa-clock-rotate-left me-1"></i><span data-i18n="data_sync_view_history">History</span>
                            </button>
                            <button type="button" class="btn btn-outline-brand btn-sm ds-sync-one-btn" data-entity-type="${type}">
                                <i class="fa-solid fa-rotate me-1"></i><span data-i18n="sync_now">Sync Now</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>`;
    });
    $('#dsEntityCards').html(html);
}

function dsLoadStatus() {
    $.ajax({
        url: `${BASE_URL}/api/master-data-sync.status`,
        method: 'POST',
        dataType: 'json',
        success: function (res) {
            if (!res.status) return;
            const $alert = $('#dsConnectionAlert');
            if (!res.configured) {
                $('#dsConnectionAlertText').text(langData['data_sync_not_configured'] || 'Not connected to Origami yet. The connection has not been configured -- please contact your system administrator.');
                $alert.removeClass('d-none');
            } else if (!res.linked) {
                $('#dsConnectionAlertText').text(langData['data_sync_not_linked'] || 'This company is not linked to an Origami HR company yet. Set the Origami reference ID in Company Profile first.');
                $alert.removeClass('d-none');
            } else {
                $alert.addClass('d-none');
            }
            dsRenderCards(res);
        }
    });
}

function dsResultSummaryHtml(result) {
    if (!result.status) {
        return `<div class="text-danger">${escapeHtml(dsEntityLabel(result.entity_type))}: ${escapeHtml(result.message || 'Failed.')}</div>`;
    }
    const total = result.total ?? 0;
    const success = result.success ?? 0;
    const error = result.error ?? 0;
    let html = `<div>${escapeHtml(dsEntityLabel(result.entity_type))}: ${success}/${total} ${langData['data_sync_success'] || 'Success'}${error > 0 ? `, ${error} ${langData['data_sync_error'] || 'Error'}` : ''}</div>`;
    if (error > 0 && Array.isArray(result.errors)) {
        html += '<ul class="text-start small text-danger mb-0 mt-1">';
        result.errors.slice(0, 5).forEach(function (e) {
            html += `<li>${escapeHtml(e.message || JSON.stringify(e))}</li>`;
        });
        if (result.errors.length > 5) {
            html += `<li>... (${result.errors.length - 5} more)</li>`;
        }
        html += '</ul>';
    }
    return html;
}

// 2026-09-02, explicit request: "อยากให้มี % บอกด้วยครับ" -- shared by BOTH "Sync All" (6 steps) and a
// single card's own "Sync Now" (1 step), so the SAME honest step-by-step mechanism drives both
// instead of Sync All getting real progress and Sync Now getting something different. Runs
// api/master-data-sync.sync-one ONCE PER entity type SEQUENTIALLY (not the single all-in-one
// api/master-data-sync.sync-all call) specifically so there's a real, verifiable "N of total done"
// figure to report after each one resolves -- a single combined backend call has no way to report
// partial progress mid-flight (confirmed: MasterDataSyncOrchestrator::syncAllMasterData() loops
// synchronously server-side and returns only once everything is done, see that method's own
// docblock), so client-side sequencing is the only way to get real (not simulated) percentages
// without backend rework.
// `onStep(doneCount, total, result)` fires after EACH entity type completes (success or failure --
// one failed sync doesn't stop the rest, same "don't let one bad entity block everything else"
// behavior the old single sync-all call already had). `onDone(allResults)` fires once at the end.
function dsRunSyncSequence(entityTypes, onStep, onDone) {
    const results = [];
    let i = 0;
    function next() {
        if (i >= entityTypes.length) {
            onDone(results);
            return;
        }
        const entityType = entityTypes[i];
        $.ajax({
            url: `${BASE_URL}/api/master-data-sync.sync-one`,
            method: 'POST',
            dataType: 'json',
            data: { entity_type: entityType },
            success: function (res) {
                results.push(res.status ? res : Object.assign({ entity_type: entityType }, res));
                i++;
                onStep(i, entityTypes.length, results[results.length - 1]);
                next();
            },
            error: function () {
                results.push({ status: false, entity_type: entityType, message: langData['save_failed'] || 'Failed.' });
                i++;
                onStep(i, entityTypes.length, results[results.length - 1]);
                next();
            }
        });
    }
    next();
}

function dsSyncOne(entityType, $btn) {
    const title = langData['confirm_data_sync_one_title'] || 'Sync Now?';
    const tpl = langData['confirm_data_sync_one_message'] || 'This will pull the latest {entity} data from Origami, overwriting matching local records. Continue?';
    const message = tpl.replace('{entity}', dsEntityLabel(entityType));
    showConfirm(title, message, function () {
        $btn.prop('disabled', true);
        const $wrap = $(`.ds-progress-wrap[data-ds-progress="${entityType}"]`).removeClass('d-none');
        const $bar = $wrap.find('.progress-bar').css('width', '0%');
        dsRunSyncSequence([entityType], function (done, total) {
            $bar.css('width', `${Math.round((done / total) * 100)}%`);
        }, function (results) {
            $btn.prop('disabled', false);
            setTimeout(function () { $wrap.addClass('d-none'); }, 600);
            dsLoadStatus();
            if (dsHistoryTableInited && tb_data_sync_history) { tb_data_sync_history.ajax.reload(null, false); }
            const result = results[0];
            if (result && result.status) {
                if (typeof showSuccess === 'function') { showSuccess(dsResultSummaryHtml(result)); }
            } else if (typeof showError === 'function') {
                showError(dsResultSummaryHtml(result || { status: false, entity_type: entityType }));
            }
        });
    });
}

$(document).on('click', '.ds-sync-one-btn', function () {
    const entityType = $(this).data('entity-type');
    dsSyncOne(entityType, $(this));
});

$(document).on('click', '#btnSyncAllMasterData', function () {
    const $btn = $(this);
    showConfirm(
        langData['confirm_sync_all_title'] || 'Sync All?',
        langData['confirm_sync_all_message'] || 'This will pull the latest department, position, shift, branch, team, and holiday data from Origami, overwriting matching local records. Continue?',
        function () {
            $btn.prop('disabled', true);
            // Also disable every per-card "Sync Now" button while Sync All is running -- it's about
            // to sync all of them anyway, and letting one be clicked mid-run would race a second
            // sync-one call for the same entity type against the one Sync All is already making.
            $('.ds-sync-one-btn').prop('disabled', true);
            const $wrap = $('#dsSyncAllProgressWrap').css('display', 'flex');
            const $bar = $('#dsSyncAllProgressBar').css('width', '0%');
            const $label = $('#dsSyncAllProgressLabel').text('0%');
            DS_ENTITY_ORDER.forEach(function (type) {
                $(`.ds-progress-wrap[data-ds-progress="${type}"]`).removeClass('d-none').find('.progress-bar').css('width', '0%');
            });
            dsRunSyncSequence(DS_ENTITY_ORDER, function (done, total, lastResult) {
                const pct = Math.round((done / total) * 100);
                $bar.css('width', `${pct}%`);
                $label.text(`${pct}% (${done}/${total})`);
                if (lastResult) {
                    $(`.ds-progress-wrap[data-ds-progress="${lastResult.entity_type}"]`).find('.progress-bar').css('width', '100%');
                }
            }, function (results) {
                $btn.prop('disabled', false);
                setTimeout(function () {
                    $wrap.css('display', 'none');
                    DS_ENTITY_ORDER.forEach(function (type) { $(`.ds-progress-wrap[data-ds-progress="${type}"]`).addClass('d-none'); });
                }, 800);
                dsLoadStatus();
                if (dsHistoryTableInited && tb_data_sync_history) { tb_data_sync_history.ajax.reload(null, false); }
                let html = '';
                results.forEach(function (r) { html += dsResultSummaryHtml(r); });
                if (typeof showSuccess === 'function') { showSuccess(html); }
            });
        }
    );
});

function dsStatusBadge(status) {
    const map = {
        completed: ['bg-success-subtle text-success', 'data_sync_status_completed', 'Completed'],
        failed: ['bg-danger-subtle text-danger', 'data_sync_status_failed', 'Failed'],
        running: ['bg-info-subtle text-info', 'data_sync_status_running', 'Running'],
    };
    const [cls, key, fallback] = map[status] || ['bg-secondary-subtle text-secondary', '', status];
    const label = (key && langData[key]) || fallback;
    return `<span class="badge ${cls}">${escapeHtml(label)}</span>`;
}

function dsSyncedByCell(row) {
    if (!row.triggered_by) {
        return `<span class="text-muted">${langData['data_sync_system'] || 'System'}</span>`;
    }
    const name = (currentLang === 'th' ? `${row.triggered_by_name_th || ''} ${row.triggered_by_surname_th || ''}` : `${row.triggered_by_name_en || ''} ${row.triggered_by_surname_en || ''}`).trim();
    return escapeHtml(name || row.triggered_by_employee_no || `#${row.triggered_by}`);
}

function dsShowErrorDetail(errorDetailJson) {
    let items = [];
    try {
        const parsed = JSON.parse(errorDetailJson);
        items = Array.isArray(parsed) ? parsed : [parsed];
    } catch (e) {
        items = [{ message: errorDetailJson }];
    }
    let html = '<ul class="text-start small mb-0">';
    items.forEach(function (item) {
        html += `<li>${escapeHtml(item.message || JSON.stringify(item))}${item.ref_id !== undefined ? ` (ref_id: ${escapeHtml(item.ref_id)})` : ''}</li>`;
    });
    html += '</ul>';
    if (typeof Swal !== 'undefined') {
        Swal.fire({ title: langData['data_sync_error_detail'] || 'Error Detail', html: html, icon: 'error' });
    }
}

$(document).on('click', '.ds-view-error-btn', function () {
    const errorDetail = $(this).data('error-detail');
    dsShowErrorDetail(errorDetail);
});

// 2026-09-02, explicit request: "ในประวัติให้มี Filter ด้วย" -- date range (station-filter) reloads the
// table server-side (SyncBatchModel::list()'s own date_from/date_to filter); entity_type/status stay
// as the pre-existing Excel-style per-column filters on the table header itself.
function dsHistoryFilterParams(d) {
    d.date_from = $('#dsHistoryFilterDateFrom').val() ? toIsoDate($('#dsHistoryFilterDateFrom').val()) : '';
    d.date_to = $('#dsHistoryFilterDateTo').val() ? toIsoDate($('#dsHistoryFilterDateTo').val()) : '';
}
function dsUpdateClearHistoryFilterVisibility() {
    const hasFilter = !!($('#dsHistoryFilterDateFrom').val() || $('#dsHistoryFilterDateTo').val());
    $('#dsHistoryFilterClearRow').toggleClass('d-none', !hasFilter);
}
$(document).on('click', '#dsHistoryStationFilterToggle', function () {
    const $filter = $('#dsHistoryStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('changeDate', '#dsHistoryFilterDateFrom, #dsHistoryFilterDateTo', function () {
    dsUpdateClearHistoryFilterVisibility();
    if (tb_data_sync_history) tb_data_sync_history.ajax.reload();
});
$(document).on('click', '#btnClearDsHistoryFilter', function () {
    $('#dsHistoryFilterDateFrom').val('');
    if (typeof $.fn.datepicker === 'function') $('#dsHistoryFilterDateFrom').datepicker('update');
    $('#dsHistoryFilterDateTo').val('');
    if (typeof $.fn.datepicker === 'function') $('#dsHistoryFilterDateTo').datepicker('update');
    dsUpdateClearHistoryFilterVisibility();
    if (tb_data_sync_history) tb_data_sync_history.ajax.reload();
});

function dsInitHistoryTable() {
    if (dsHistoryTableInited) return;
    dsHistoryTableInited = true;
    tb_data_sync_history = $('#tb_data_sync_history').DataTable({
        ajax: {
            url: `${BASE_URL}/api/master-data-sync.history`,
            type: 'POST',
            data: dsHistoryFilterParams,
            dataSrc: 'data',
        },
        columns: [
            { data: 'entity_type', render: { display: (d) => escapeHtml(dsEntityLabel(d)), sort: (d) => d, filter: (d) => d } },
            { data: 'status', render: { display: (d) => dsStatusBadge(d), sort: (d) => d, filter: (d) => d } },
            { data: 'total_count', defaultContent: '0' },
            { data: 'success_count', defaultContent: '0' },
            { data: 'error_count', defaultContent: '0' },
            { data: null, render: (d, t, row) => dsSyncedByCell(row) },
            {
                data: 'started_at',
                render: {
                    display: (d) => d ? (typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(d) : d) : '-',
                    sort: (d) => d || '',
                    filter: (d) => d || '',
                }
            },
            {
                data: 'completed_at',
                render: {
                    display: (d) => d ? (typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(d) : d) : '-',
                    sort: (d) => d || '',
                    filter: (d) => d || '',
                }
            },
            {
                data: null, orderable: false,
                render: function (row) {
                    if (!row.error_detail) return '';
                    const encoded = escapeHtml(row.error_detail).replace(/"/g, '&quot;');
                    return `<button type="button" class="btn btn-link btn-sm ds-view-error-btn" data-error-detail="${encoded}"><i class="fa-solid fa-circle-info me-1"></i><span data-i18n="data_sync_view_errors">View Errors</span></button>`;
                }
            },
        ],
        order: [[6, 'desc']],
        responsive: true,
        initComplete: function () {
            initExcelColumnFilters(this.api(), {
                mode: 'client',
                columns: [
                    { index: 0, key: 'entity_type' },
                    { index: 1, key: 'status' },
                ]
            });
        }
    });
}

// 2026-09-07: the per-card History modal -- rebuilt from scratch each open (destroy+recreate,
// same pattern this app's own Reports module uses for #payslipRosterModal/#cycleReportHistoryModal)
// since the SAME modal/table id is reused for whichever entity type was clicked, not one modal per
// type. Unfiltered by date range on purpose -- this is "this ONE type's full history," a narrower
// question than the Sync History tab's own filterable table, so no extra filter UI was added here.
let tb_ds_card_history = null;
function dsOpenCardHistory(type) {
    $('#dsCardHistoryModalTitle').text(dsEntityLabel(type));
    bootstrap.Modal.getOrCreateInstance(document.getElementById('dsCardHistoryModal')).show();
    if ($.fn.DataTable.isDataTable('#tb_ds_card_history')) {
        tb_ds_card_history.destroy();
        $('#tb_ds_card_history tbody').empty();
    }
    tb_ds_card_history = $('#tb_ds_card_history').DataTable({
        ajax: {
            url: `${BASE_URL}/api/master-data-sync.history`,
            type: 'POST',
            data: function (d) { d.entity_type = type; },
            dataSrc: 'data',
        },
        columns: [
            { data: 'status', render: { display: (d) => dsStatusBadge(d), sort: (d) => d, filter: (d) => d } },
            { data: 'total_count', defaultContent: '0' },
            { data: 'success_count', defaultContent: '0' },
            { data: 'error_count', defaultContent: '0' },
            { data: null, render: (d, t, row) => dsSyncedByCell(row) },
            {
                data: 'started_at',
                render: {
                    display: (d) => d ? (typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(d) : d) : '-',
                    sort: (d) => d || '',
                    filter: (d) => d || '',
                }
            },
            {
                data: 'completed_at',
                render: {
                    display: (d) => d ? (typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(d) : d) : '-',
                    sort: (d) => d || '',
                    filter: (d) => d || '',
                }
            },
            {
                data: null, orderable: false,
                render: function (row) {
                    if (!row.error_detail) return '';
                    const encoded = escapeHtml(row.error_detail).replace(/"/g, '&quot;');
                    return `<button type="button" class="btn btn-link btn-sm ds-view-error-btn" data-error-detail="${encoded}"><i class="fa-solid fa-circle-info me-1"></i><span data-i18n="data_sync_view_errors">View Errors</span></button>`;
                }
            },
        ],
        order: [[5, 'desc']],
        responsive: true,
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
    });
}
$(document).on('click', '.ds-view-history-btn', function () {
    dsOpenCardHistory($(this).data('entity-type'));
});

function initDataSyncPage() {
    dsLoadStatus();
    if (typeof initDatepicker === 'function') {
        initDatepicker('#dsHistoryFilterDateFrom');
        initDatepicker('#dsHistoryFilterDateTo');
    }
    // Lazy-init on first shown -- this table is no longer the default-active tab (Sync is), and this
    // app has hit the "DataTable constructed inside a display:none Bootstrap tab collapses every
    // column to 0 width" bug enough times elsewhere that it's a standing habit to guard against here
    // too (see e.g. Employee List's own Login History tab for the identical precedent).
    $(document).on('shown.bs.tab', '#ds-history-tab', function () {
        dsInitHistoryTable();
    });
}
