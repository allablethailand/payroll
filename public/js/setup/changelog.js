/**
 * 2026-09-05, Backlog Phase 13 -- Help > Version. Plain fetch+render list, platform-wide (no
 * per-company scoping), see ChangelogModel's own docblock.
 */
(function () {
    function escapeHtmlCl(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    function renderChangelog(rows) {
        if (!rows || !rows.length) {
            $('#versionList').html('<div class="text-muted" data-i18n="version_empty">No release notes yet.</div>');
            if (typeof applyLanguage === 'function') applyLanguage();
            return;
        }
        let html = '';
        rows.forEach(function (row) {
            const title = currentLang === 'en' ? row.title_en : row.title_th;
            const body = currentLang === 'en' ? row.body_en : row.body_th;
            html += '<div class="card-surface mb-3 version-entry">'
                + '<div class="d-flex justify-content-between align-items-start mb-1">'
                + '<h6 class="fw-bold mb-0">' + escapeHtmlCl(title) + '</h6>'
                + '<span class="badge bg-primary-subtle text-primary-emphasis">' + escapeHtmlCl(row.version_label) + '</span>'
                + '</div>'
                + '<div class="text-muted small mb-2">' + escapeHtmlCl(row.release_date) + '</div>'
                + '<div>' + escapeHtmlCl(body) + '</div>'
                + '</div>';
        });
        $('#versionList').html(html);
        if (typeof applyLanguage === 'function') applyLanguage();
    }

    let lastChangelogRows = null;
    function loadChangelog() {
        $.get(`${BASE_URL}/api/help.changelog-list`).done(function (res) {
            if (res && res.status) {
                lastChangelogRows = res.data;
                renderChangelog(res.data);
            }
        });
    }

    window.changelogRefreshLanguage = function () {
        if (lastChangelogRows) renderChangelog(lastChangelogRows);
    };

    $(document).ready(function () {
        (window.langReady || Promise.resolve()).then(function () {
        if (!$('#versionList').length) return;
        loadChangelog();
        });
    });
})();
