/**
 * Shared "Sync from Origami" button widget (Backlog Phase 9, T050, 2026-09-04) -- ONE reusable
 * button+confirm+AJAX+result-summary implementation for the DIRECT overwrite-style sync pattern
 * (MasterDataSyncOrchestrator::syncEntity() / api/master-data-sync.sync-one, and CompanySyncModel::
 * sync() / api/company.sync-origami). Both already existed as separate, near-duplicate button
 * implementations before this round (Company Profile's own #btnSyncCompanyOrigami, data-sync.js's
 * per-card .ds-sync-one-btn) -- this consolidates the SIMPLE single-click case into one place so a
 * future 3rd sync point is a few lines, not another copy-paste.
 *
 * Deliberately NOT used for:
 * - data-sync.js's own per-card/"Sync All" buttons -- those need real sequential multi-step
 *   progress (dsRunSyncSequence()'s own % bar), a genuinely different UX this simple widget doesn't
 *   attempt to replicate. Left untouched, still calls the same api/master-data-sync.sync-one
 *   endpoint directly, own result-summary rendering (dsResultSummaryHtml()) left as-is too rather
 *   than risking that already-working, already-tested-by-use sequencing feature for no real gain.
 * - Department/Position/Team's own "Sync from Origami" buttons on the Organizational Structure tab
 *   (org-structure-sync.js, `.btn-open-org-sync`) -- that is a DIFFERENT, richer, review-first
 *   mechanism (fetch candidates -> admin ticks New/Existing -> apply only what's selected) sourced
 *   from OrigamiEmployeeCandidateClient::fetchFilterOptions(), not the direct-overwrite
 *   MasterDataSyncOrchestrator this widget calls. Branch has no equivalent candidate source (that
 *   client's filter-options response has no `branches` key at all -- confirmed by reading it, not
 *   guessed), so it gets this simpler direct widget instead, same as Shift/Holiday.
 *
 * Used for: Company Profile's Company Information tab (retrofit, same visible wording/behavior as
 * before), Organizational Structure's Branch tab (NEW -- Branch was excluded from
 * org-structure-sync.js's review-first picker only because its data source has no branch
 * candidates, not because Branch has nothing real to sync -- api/hr/master/branches is a live,
 * already-wired endpoint via MasterDataSyncOrchestrator/BranchSyncer), and Setup & Rules' Shift and
 * Holiday tabs (NEW -- both had zero sync entry point anywhere on their own screen before this).
 */

/** Builds a consistent result-summary block for BOTH response shapes this widget's callers use:
 *  the rich {status, total, success, error, errors} shape MasterDataSyncOrchestrator returns, and
 *  the plain {status, message} shape CompanySyncModel returns (single-record sync, nothing to
 *  count). `entityLabel` may be '' (Company sync has no per-record label worth repeating). */
function origamiSyncResultSummaryHtml(entityLabel, res) {
    const label = escapeHtml(entityLabel || '');
    const prefix = label ? `${label}: ` : '';
    if (!res || !res.status) {
        return `<div class="text-danger">${prefix}${escapeHtml((res && res.message) || (typeof langData !== 'undefined' && langData['save_failed']) || 'Failed.')}</div>`;
    }
    if (res.total === undefined) {
        return `<div>${escapeHtml(res.message || (typeof langData !== 'undefined' && langData['save_success']) || 'Saved successfully.')}</div>`;
    }
    const total = res.total ?? 0, success = res.success ?? 0, error = res.error ?? 0;
    let html = `<div>${prefix}${success}/${total} ${(typeof langData !== 'undefined' && langData['data_sync_success']) || 'Success'}${error > 0 ? `, ${error} ${(typeof langData !== 'undefined' && langData['data_sync_error']) || 'Error'}` : ''}</div>`;
    if (error > 0 && Array.isArray(res.errors)) {
        html += '<ul class="text-start small text-danger mb-0 mt-1">';
        res.errors.slice(0, 5).forEach(function (e) { html += `<li>${escapeHtml(e.message || JSON.stringify(e))}</li>`; });
        if (res.errors.length > 5) { html += `<li>... (${res.errors.length - 5} more)</li>`; }
        html += '</ul>';
    }
    return html;
}

/**
 * initOrigamiSyncButton({ container, url, payload, entityLabel, confirmTitle, confirmMessage, onSuccess })
 * Appends one standardized "Sync from Origami" button into `container` (a jQuery element -- e.g. a
 * DataTable's own `.dt-search` toolbar) and wires: SweetAlert2 confirm -> POST `url` with `payload`
 * (plain object, or a function returning one, evaluated fresh on each click) -> a standardized
 * success/failure SweetAlert2 result via origamiSyncResultSummaryHtml() -> `onSuccess(res)` for the
 * caller's own refresh logic (e.g. `table.ajax.reload(null, false)`). Returns the button (jQuery).
 * Idempotent -- skips appending (returns the existing button instead) if `container` already has
 * one, same guard every other `initComplete`-injected button in this app already follows since
 * DataTable's own `initComplete` can re-fire on redraw in some configurations.
 */
function initOrigamiSyncButton(opts) {
    const $container = opts && opts.container;
    if (!$container || !$container.length) return null;
    const $existing = $container.find('.origami-sync-btn');
    if ($existing.length > 0) { return $existing; }
    const label = (typeof langData !== 'undefined' && langData['sync_from_origami']) || 'Sync from Origami';
    const $btn = $(`
        <button type="button" class="btn btn-outline-brand btn-sm ms-1 origami-sync-btn">
            <i class="fa-solid fa-rotate me-1"></i><span data-i18n="sync_from_origami">${escapeHtml(label)}</span>
        </button>
    `);
    $container.append($btn);
    if (typeof updateText === 'function') { updateText($btn[0]); }
    $btn.on('click', function () {
        const title = opts.confirmTitle || (typeof langData !== 'undefined' && langData['confirm_data_sync_one_title']) || 'Sync Now?';
        const tpl = opts.confirmMessage || (typeof langData !== 'undefined' && langData['confirm_data_sync_one_message']) || 'This will pull the latest {entity} data from Origami, overwriting matching local records. Continue?';
        const message = tpl.replace('{entity}', opts.entityLabel || '');
        showConfirm(title, message, function () {
            $btn.prop('disabled', true);
            const payload = typeof opts.payload === 'function' ? opts.payload() : (opts.payload || {});
            $.ajax({
                url: opts.url,
                method: 'POST',
                dataType: 'json',
                data: payload,
                success: function (res) {
                    $btn.prop('disabled', false);
                    if (res && res.status) {
                        if (typeof showSuccess === 'function') { showSuccess(origamiSyncResultSummaryHtml(opts.entityLabel, res)); }
                        if (typeof opts.onSuccess === 'function') { opts.onSuccess(res); }
                    } else if (typeof showError === 'function') {
                        showError(origamiSyncResultSummaryHtml(opts.entityLabel, res));
                    }
                },
                error: function () {
                    $btn.prop('disabled', false);
                    if (typeof showError === 'function') { showError((typeof langData !== 'undefined' && langData['save_failed']) || 'An error occurred while syncing.'); }
                }
            });
        });
    });
    return $btn;
}
