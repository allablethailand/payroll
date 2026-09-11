/**
 * 2026-09-05, Backlog Phase 13 -- Help Drawer: a persistent floating button (every page) that
 * slides in a right-side panel with contextual help for the CURRENT page. Loaded globally (every
 * page, via footer.php).
 *
 * `page_key` is derived directly from the URL path (BASE_URL stripped, slashes/dashes -> `_`),
 * NOT a hand-maintained map -- e.g. `/setup/tax-statutory` -> `setup_tax_statutory`,
 * `/payroll-process` -> `payroll_process`. This means every page in the app already has a stable
 * key with zero per-page wiring; whether real content EXISTS for that key is a separate question
 * (help_drawer_content is empty for most pages right now, see HelpDrawerContentModel's own
 * docblock for why -- explicit request confirmed via AskUserQuestion: mechanism + real content
 * for ~5 main pages this round, not full coverage). An unwritten page shows a placeholder message
 * in the drawer, same "always show the concept exists, never hide the button" reasoning that
 * memory records for this decision.
 */
(function () {
    function currentPageKey() {
        let path = window.location.pathname;
        const baseUrlPath = new URL(BASE_URL, window.location.origin).pathname;
        if (path.startsWith(baseUrlPath)) {
            path = path.slice(baseUrlPath.length);
        }
        path = path.replace(/^\/+|\/+$/g, '');
        if (path === '') return 'dashboard';
        return path.replace(/[\/-]/g, '_');
    }

    function escapeHtmlHd(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    let lastDrawerData = null;
    function renderDrawer(data) {
        if (!data) {
            $('#helpDrawerPanelBody').html('<div class="text-muted" data-i18n="help_drawer_empty">No help content has been written for this page yet.</div>');
        } else {
            const title = currentLang === 'en' ? data.title_en : data.title_th;
            const body = currentLang === 'en' ? data.body_en : data.body_th;
            $('#helpDrawerPanelBody').html('<h6 class="fw-bold mb-2">' + escapeHtmlHd(title) + '</h6><div>' + escapeHtmlHd(body).replace(/\n/g, '<br>') + '</div>');
        }
        if (typeof applyLanguage === 'function') applyLanguage();
    }

    function loadDrawerContent() {
        $.get(`${BASE_URL}/api/help.drawer-content`, { page_key: currentPageKey() }).done(function (res) {
            lastDrawerData = (res && res.status) ? res.data : null;
            renderDrawer(lastDrawerData);
        });
    }

    window.helpDrawerRefreshLanguage = function () {
        renderDrawer(lastDrawerData);
    };

    $(document).ready(function () {
        (window.langReady || Promise.resolve()).then(function () {
        loadDrawerContent();
        });
    });

    $(document).on('click', '#helpDrawerToggleBtn', function () {
        $('#helpDrawerPanel').addClass('help-drawer-panel-open').attr('aria-hidden', 'false');
    });
    $(document).on('click', '#helpDrawerCloseBtn', function () {
        $('#helpDrawerPanel').removeClass('help-drawer-panel-open').attr('aria-hidden', 'true');
    });
})();
