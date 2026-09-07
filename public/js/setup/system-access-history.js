/**
 * 2026-09-05, Backlog Phase 13 -- Profile > "System Access History", self-service. Fetches on
 * demand (modal open), not on every page load -- unlike the T&C login-gate check and Help Drawer,
 * this has no reason to run until the employee actually opens it.
 */
(function () {
    function escapeHtmlSah(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    $(document).on('show.bs.modal', '#systemAccessHistoryModal', function () {
        $('#systemAccessHistoryBody').html('<tr><td colspan="4" class="text-center text-muted">…</td></tr>');
        $.get(`${BASE_URL}/api/employee-login-log.my-history`).done(function (res) {
            const rows = (res && res.status) ? res.data : [];
            if (!rows.length) {
                $('#systemAccessHistoryBody').html('<tr><td colspan="4" class="text-center text-muted" data-i18n="system_access_history_empty">No login history yet.</td></tr>');
                if (typeof applyLanguage === 'function') applyLanguage();
                return;
            }
            let html = '';
            rows.forEach(function (r) {
                html += '<tr>'
                    + '<td>' + escapeHtmlSah(r.login_at || r.created_at || '') + '</td>'
                    + '<td>' + escapeHtmlSah(r.ip_address || '') + '</td>'
                    + '<td>' + escapeHtmlSah(r.device_type || '') + '</td>'
                    + '<td>' + escapeHtmlSah(r.browser_name || '') + '</td>'
                    + '</tr>';
            });
            $('#systemAccessHistoryBody').html(html);
        });
    });
})();
