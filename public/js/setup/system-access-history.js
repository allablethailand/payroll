/**
 * 2026-09-05, Backlog Phase 13 -- Profile > "System Access History", self-service. Fetches on
 * demand (modal open), not on every page load -- unlike the T&C login-gate check and Help Drawer,
 * this has no reason to run until the employee actually opens it.
 *
 * 2026-09-07, explicit request: "ให้เป็น Datatable ครับ" -- was a plain hand-built <tbody>; now a
 * real client-side DataTable (`data:` array, not `ajax:`, since the whole bounded ~50-row fetch
 * already happens in one $.get() before the table is ever built -- feeding it as a pre-fetched
 * array is simpler than wiring a DataTables `ajax` config around a request this page already made
 * itself). Destroyed and rebuilt on every `show.bs.modal` (not `ajax.reload()`) since this is a
 * fresh fetch of "my own" history each time, not a table that stays mounted across opens.
 */
(function () {
    function escapeHtmlSah(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }
    let dtSystemAccessHistory = null;

    $(document).on('show.bs.modal', '#systemAccessHistoryModal', function () {
        if (dtSystemAccessHistory) {
            dtSystemAccessHistory.destroy();
            dtSystemAccessHistory = null;
        }
        $('#tb_system_access_history tbody').empty();
        $.get(`${BASE_URL}/api/employee-login-log.my-history`).done(function (res) {
            const rows = (res && res.status) ? (res.data || []) : [];
            dtSystemAccessHistory = $('#tb_system_access_history').DataTable({
                data: rows,
                responsive: true,
                pageLength: pageLength,
                lengthMenu: lengthMenu,
                language: { ...getTableLang(), emptyTable: langData['system_access_history_empty'] || 'No login history yet.' },
                order: [[0, 'desc']],
                columns: [
                    // object-form render: sort/filter stay on the raw ISO timestamp -- this is a
                    // CLIENT-side table (no serverSide), so sorting on a formatted display string
                    // would sort lexicographically instead of chronologically (CLAUDE.md's own
                    // Table convention -- the exact bug category already found/fixed elsewhere in
                    // this app for the same reason).
                    { data: null, render: { display: (d, t, r) => escapeHtmlSah(r.login_at || r.created_at || ''), sort: (d, t, r) => r.login_at || r.created_at || '', filter: (d, t, r) => r.login_at || r.created_at || '' } },
                    { data: 'ip_address', render: (v) => escapeHtmlSah(v || '-') },
                    { data: 'device_type', render: (v) => escapeHtmlSah(v || '-') },
                    { data: 'browser_name', render: (v) => escapeHtmlSah(v || '-') },
                ],
                drawCallback: function () { getTableLang(); }
            });
        });
    });
})();
