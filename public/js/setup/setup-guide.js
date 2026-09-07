/**
 * 2026-09-05, Backlog Phase 13 -- Help > Setup Guide. Company-level setup checklist, see
 * SetupGuideModel's own docblock for scope/reasoning. Plain fetch+render, no DataTable/Select2
 * needed -- a short, fixed-length checklist, not a record list.
 */
(function () {
    function escapeHtmlSg(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    function renderChecklist(data) {
        const pct = data.percent || 0;
        $('#sgProgressText').text(pct + '%');
        $('#sgProgressBar').css('width', pct + '%').removeClass('bg-warning bg-success').addClass(pct >= 100 ? 'bg-success' : 'bg-warning');

        let html = '';
        (data.items || []).forEach(function (item) {
            const label = currentLang === 'en' ? item.label_en : item.label_th;
            const detail = currentLang === 'en' ? item.detail_en : item.detail_th;
            const iconClass = item.done ? 'fa-solid fa-circle-check text-success' : 'fa-regular fa-circle text-muted';
            html += '<div class="card-surface mb-2 sg-item' + (item.done ? ' sg-item-done' : '') + '">'
                + '<div class="d-flex align-items-start gap-3">'
                + '<i class="' + iconClass + ' mt-1 fs-5"></i>'
                + '<div class="flex-grow-1">'
                + '<div class="fw-semibold">' + escapeHtmlSg(label) + '</div>'
                + '<div class="text-muted small">' + escapeHtmlSg(detail) + '</div>'
                + '</div>'
                + (item.done
                    ? '<span class="badge bg-success-subtle text-success align-self-center" data-i18n="setup_guide_done">Done</span>'
                    : '<a href="' + BASE_URL + item.link + '" class="btn btn-sm btn-outline-primary align-self-center" data-i18n="setup_guide_go_to_setting">Go to setting</a>')
                + '</div></div>';
        });
        $('#sgChecklist').html(html);
        if (typeof applyLanguage === 'function') applyLanguage();
    }

    let lastChecklistData = null;
    function loadChecklist() {
        $.get(`${BASE_URL}/api/help.checklist`).done(function (res) {
            if (res && res.status) {
                lastChecklistData = res.data;
                renderChecklist(res.data);
            }
        });
    }

    // Exposed globally (see app.js's own changeLanguage() call site) -- re-renders from the
    // already-fetched data instead of a full re-fetch, since only the DISPLAYED text changes.
    window.sgRefreshChecklistLanguage = function () {
        if (lastChecklistData) renderChecklist(lastChecklistData);
    };

    $(document).ready(function () {
        if (!$('#sgChecklist').length) return;
        loadChecklist();
    });
})();
