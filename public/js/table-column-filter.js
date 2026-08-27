/**
 * Excel-style per-column header filter for DataTables.
 *
 * 2026-08-27, explicit request: "ในตารางทุกตาราง ใน thead ของแต่ละ Column เพิ่มให้สามารถ Filter ได้ โดย
 * ดึงมาจากข้อมูลใน Column นั้นๆ ยกเว้นพวกปุ่มดำเนินการไม่ต้องมี หรือ Column ที่ไม่สามารถ Filter ได้ เหมือนกับ
 * Excel ครับ และเลือกทั้งหมดได้ หรือเลือกเฉพาะข้อมูลที่กรองดูได้" -- proof-of-concept wired into Employee
 * List first (public/js/employee/list.js), confirmed via AskUserQuestion before rolling out to the
 * other 81 DataTable instances in this app: (1) server-side tables get a REAL backend distinct-
 * values endpoint per table (not an approximation from whatever page happens to be loaded), (2) this
 * ONE shared component gets proven on one table before being cloned everywhere else.
 *
 * Two source modes, since a server-side DataTable (`serverSide:true`) never holds more than the
 * CURRENT PAGE of rows in the browser -- a real Excel-style "every value this column could hold"
 * list is only possible by asking the server:
 *   - `mode: 'client'` -- unique values come straight from `column().data().unique()` (the WHOLE
 *     dataset, since a client-side DataTable loads everything into the browser regardless of
 *     pagination). Filtering itself also happens client-side via a custom
 *     `$.fn.dataTable.ext.search` predicate scoped to this one table instance.
 *   - `mode: 'server'` -- unique values are fetched via `fetchValues(key, done)`; filtering is left
 *     entirely to the caller's own `ajax.data`/backend (this module never touches the network for
 *     the actual filtered fetch, only for the checkbox list) -- read `getColumnFilterValues(dt)` in
 *     that `data` function and forward it as-is (already shaped `{ colKey: [selected, values] }`,
 *     matching what a PHP backend receives as nested POST array fields).
 *
 * Deliberately excludes: no filter UI is added to any column not explicitly listed in `columns` --
 * this is what keeps action-button columns and other non-filterable columns (avatars, progress
 * bars, ...) untouched, rather than needing a separate exclude-list.
 *
 * Usage:
 *   const dt = $('#tb_x').DataTable({ ... });
 *   initExcelColumnFilters(dt, {
 *       mode: 'server', // or 'client'
 *       columns: [ { index: 1, key: 'employee_no' }, { index: 2, key: 'name' }, ... ],
 *       fetchValues: function (key, done) { // server mode only
 *           $.ajax({ url: ..., method: 'POST', data: { column: key, ...otherCurrentFilters() } })
 *               .done(function (res) { done(res.values || []); });
 *       },
 *       onApply: function () { dt.ajax.reload(null, false); }, // server mode only
 *   });
 *   // server mode -- inside the table's own `ajax.data` function, build the API from the `settings`
 *   // param DataTables itself passes in (`function (d, settings) { ... }`), NOT the outer
 *   // `tb_x = $(...).DataTable({...})` variable -- DataTables calls `ajax.data` synchronously to
 *   // build the very FIRST request while that assignment is still in progress, so `tb_x` is still
 *   // undefined at that exact moment (real bug hit + fixed wiring this into Employee List: crashed
 *   // every page load with "Cannot read properties of undefined (reading 'settings')" until fixed).
 *   ajax: { url: ..., data: function (d, settings) {
 *       d.column_filters = getColumnFilterValues(new $.fn.dataTable.Api(settings));
 *   } }
 */
(function () {
    let $panel = null; // one shared floating panel, reused across every table/column
    let panelOwner = null; // { dt, key, index, config } the panel is currently open for

    function ensurePanel() {
        if ($panel) return $panel;
        $panel = $(`
            <div class="tcf-panel d-none">
                <div class="tcf-panel-header">
                    <span class="tcf-panel-title"></span>
                    <button type="button" class="tcf-panel-close" title=""><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="tcf-search-wrap">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" class="tcf-search" placeholder="">
                </div>
                <div class="tcf-select-all-row">
                    <label class="tcf-item">
                        <input type="checkbox" class="tcf-select-all" checked>
                        <span class="tcf-select-all-label"></span>
                    </label>
                </div>
                <div class="tcf-list"></div>
                <div class="tcf-footer">
                    <button type="button" class="btn btn-sm btn-link tcf-clear-btn"></button>
                    <button type="button" class="btn btn-sm btn-primary tcf-apply-btn"></button>
                </div>
            </div>
        `).appendTo('body');
        $panel.find('.tcf-panel-title').text((window.langData && langData['column_filter_title']) || 'Filter');
        $panel.find('.tcf-panel-close').attr('title', (window.langData && langData['close']) || 'Close');
        $panel.find('.tcf-search-wrap input').attr('placeholder', (window.langData && langData['search']) || 'Search...');
        $panel.find('.tcf-select-all-label').text((window.langData && langData['select_all']) || 'Select All');
        $panel.find('.tcf-clear-btn').text((window.langData && langData['clear_filter']) || 'Clear');
        $panel.find('.tcf-apply-btn').text((window.langData && langData['apply']) || 'Apply');
        return $panel;
    }

    function tableState(dt) {
        const settings = dt.settings()[0];
        if (!settings._tcf) {
            settings._tcf = { columns: {}, selected: {} }; // selected[key] = Set of checked values, absent = "all" (no filter)
        }
        return settings._tcf;
    }

    /** Public: current selections in the exact `{ colKey: [values...] }` shape a server-mode table's
     *  own `ajax.data` function should forward as `column_filters`. Only includes columns that are
     *  ACTUALLY narrowed (a column left at "select all" is simply absent -- no filter to apply).
     *  `dt` may legitimately be undefined -- DataTables calls `ajax.data(d, settings)` to build the
     *  VERY FIRST request synchronously while `.DataTable({...})` is still constructing, i.e. before
     *  the caller's own `tb_x = $(...).DataTable({...})` assignment has completed -- a naive
     *  `getColumnFilterValues(tb_x)` reading that not-yet-assigned outer variable would crash the
     *  whole page load. Since nothing could have been filtered yet on that very first request
     *  anyway, degrading to "no filters" here is always correct, not just a crash-avoidance hack --
     *  callers should still prefer building `dt` from the `settings` param DataTables itself passes
     *  into `ajax.data(d, settings)` (`new $.fn.dataTable.Api(settings)`) so LATER calls (reloads)
     *  see real state instead of always degrading. */
    window.getColumnFilterValues = function (dt) {
        if (!dt || typeof dt.settings !== 'function') return {};
        const state = tableState(dt);
        const out = {};
        Object.keys(state.selected).forEach(function (key) {
            const set = state.selected[key];
            if (set) out[key] = Array.from(set);
        });
        return out;
    };

    // 2026-08-27, real gap found while rolling this out to the rest of the app's tables: MOST
    // columns across this codebase's DataTables are `data: null` with a custom `render` function
    // (composite cells like "employee_no - name", status/category badges, formatted amounts, ...) --
    // `column(index).data()` on a `data:null` column returns the WHOLE ROW OBJECT, not a scalar, so
    // reading it directly (the original version of this function) would have produced literal
    // "[object Object]" as every "unique value" for any such column. Using the column's own RENDERED
    // ('display') output instead -- exactly what the user actually sees on screen, and exactly what
    // DataTables' own built-in global search already keys off by default -- fixes this generically
    // for every column shape without needing a per-table special case. Rendered output is frequently
    // HTML (badges, `<strong>` wrappers, icons) rather than plain text, so it's stripped through a
    // detached DOM node before being used as either a checkbox label or a filter-match key.
    function stripHtml(html) {
        if (html === null || html === undefined) return '';
        const div = document.createElement('div');
        div.innerHTML = String(html);
        return div.textContent.trim();
    }
    function renderedCellText(dt, rowIndex, colIndex) {
        let out;
        try {
            out = dt.cell(rowIndex, colIndex).render('display');
        } catch (e) {
            out = dt.cell(rowIndex, colIndex).data();
        }
        return stripHtml(out);
    }
    function uniqueClientValues(dt, index) {
        const seen = new Set();
        dt.rows().every(function (rowIdx) {
            const text = renderedCellText(dt, rowIdx, index);
            if (text !== '') seen.add(text);
        });
        return Array.from(seen).sort(function (a, b) { return a.localeCompare(b, undefined, { numeric: true }); });
    }

    function closePanel() {
        if (!$panel) return;
        $panel.addClass('d-none');
        panelOwner = null;
    }

    function renderList(values, checkedSet) {
        const $list = $panel.find('.tcf-list').empty();
        values.forEach(function (val) {
            const checked = !checkedSet || checkedSet.has(val);
            $list.append(
                $('<label class="tcf-item"></label>').append(
                    $('<input type="checkbox" class="tcf-value-cb">').val(val).prop('checked', checked),
                    $('<span></span>').text(val)
                )
            );
        });
        syncSelectAllState();
    }

    function syncSelectAllState() {
        const $boxes = $panel.find('.tcf-value-cb');
        const total = $boxes.length;
        const checkedCount = $boxes.filter(':checked').length;
        const $all = $panel.find('.tcf-select-all');
        $all.prop('checked', total > 0 && checkedCount === total);
        $all[0].indeterminate = checkedCount > 0 && checkedCount < total;
    }

    function openPanelFor(dt, col, $th) {
        const panel = ensurePanel();
        const state = tableState(dt);
        panelOwner = { dt: dt, key: col.key, index: col.index, config: col };

        const rect = $th[0].getBoundingClientRect();
        panel.css({
            top: (rect.bottom + window.scrollY + 4) + 'px',
            left: Math.max(8, Math.min(rect.left + window.scrollX, window.innerWidth - 300)) + 'px'
        }).removeClass('d-none');
        panel.find('.tcf-search').val('').off('input').on('input', function () {
            const q = $(this).val().toLowerCase();
            panel.find('.tcf-list .tcf-item').each(function () {
                const text = $(this).text().toLowerCase();
                $(this).toggleClass('d-none', text.indexOf(q) === -1);
            });
        });
        panel.find('.tcf-list').html(`<div class="tcf-loading text-secondary small py-2 text-center"><i class="fa-solid fa-spinner fa-spin me-1"></i>${(window.langData && langData['loading']) || 'Loading...'}</div>`);

        function afterValuesLoaded(values) {
            const checkedSet = state.selected[col.key] || null; // null = everything checked (no filter yet)
            renderList(values, checkedSet);
        }
        if (col.mode === 'server') {
            col.fetchValues(col.key, afterValuesLoaded);
        } else {
            afterValuesLoaded(uniqueClientValues(dt, col.index));
        }
    }

    function applyClientFilter(dt) {
        const state = tableState(dt);
        // ONE search predicate per table instance covers every configured column together, rather
        // than pushing a new closure into the shared, global $.fn.dataTable.ext.search array on
        // every apply -- registered once, lazily, the first time this table actually filters.
        if (!state._searchRegistered) {
            state._searchRegistered = true;
            $.fn.dataTable.ext.search.push(function (settings, rowData, rowIndex) {
                if (settings.nTable !== dt.table().node()) return true; // not this table
                return Object.keys(state.columns).every(function (key) {
                    const set = state.selected[key];
                    if (!set) return true; // "select all" -- no restriction from this column
                    const idx = state.columns[key];
                    return set.has(renderedCellText(dt, rowIndex, idx));
                });
            });
        }
        dt.draw();
    }

    $(document).on('click', '.tcf-filter-btn', function (e) {
        e.stopPropagation();
        const $th = $(this).closest('th');
        const $table = $(this).closest('table');
        const dt = $table.DataTable();
        const state = tableState(dt);
        const key = $(this).data('tcf-key');
        const col = state.columns[key + '__config'];
        if (panelOwner && panelOwner.key === key && panelOwner.dt === dt) {
            closePanel();
            return;
        }
        openPanelFor(dt, col, $th);
    });
    $(document).on('change', '.tcf-select-all', function () {
        const checked = $(this).is(':checked');
        $panel.find('.tcf-list .tcf-item:not(.d-none) .tcf-value-cb').prop('checked', checked);
        syncSelectAllState();
    });
    $(document).on('change', '.tcf-value-cb', function () {
        syncSelectAllState();
    });
    $(document).on('click', '.tcf-clear-btn', function () {
        if (!panelOwner) return;
        panelOwner.dt.settings()[0]._tcf.selected[panelOwner.key] = null;
        updateFilterBtnState(panelOwner.dt, panelOwner.key);
        if (panelOwner.config.mode === 'server') {
            panelOwner.config.onApply();
        } else {
            applyClientFilter(panelOwner.dt);
        }
        closePanel();
    });
    $(document).on('click', '.tcf-apply-btn', function () {
        if (!panelOwner) return;
        const $checked = $panel.find('.tcf-value-cb:checked');
        const $all = $panel.find('.tcf-value-cb');
        const state = tableState(panelOwner.dt);
        // Every visible+checked box === "select all" -- same as Excel, that's equivalent to no
        // filter at all on this column, so store null rather than a redundant full-set.
        state.selected[panelOwner.key] = ($checked.length === $all.length) ? null : new Set($checked.map(function () { return $(this).val(); }).get());
        updateFilterBtnState(panelOwner.dt, panelOwner.key);
        if (panelOwner.config.mode === 'server') {
            panelOwner.config.onApply();
        } else {
            applyClientFilter(panelOwner.dt);
        }
        closePanel();
    });
    $(document).on('click', '.tcf-panel-close', function (e) {
        e.stopPropagation();
        closePanel();
    });
    $(document).on('click', function (e) {
        if ($panel && !$panel.hasClass('d-none') && !$(e.target).closest('.tcf-panel, .tcf-filter-btn').length) {
            closePanel();
        }
    });
    // 2026-08-27, explicit bug report: scrolling the page (e.g. scrolling back up after opening the
    // panel) was closing it -- NOT wanted, since the panel is meant to stay open while the user
    // scrolls to compare values. Only `resize` still closes it (the panel's position is computed
    // once, from the filter button's rect, at open time -- a viewport resize can leave it visibly
    // misplaced, which a scroll alone does not). Explicit close is now via the X button or an
    // outside click instead.
    $(window).on('resize', function () {
        if (panelOwner) closePanel();
    });

    function updateFilterBtnState(dt, key) {
        const state = tableState(dt);
        const active = !!state.selected[key];
        $(dt.table().node()).find(`.tcf-filter-btn[data-tcf-key="${key}"]`).toggleClass('tcf-filter-active', active);
    }

    window.initExcelColumnFilters = function (dt, options) {
        const state = tableState(dt);
        // 2026-08-27, rolling this out beyond Employee List surfaced a real case Employee List
        // itself never had: several tables in this app set `ordering: false` globally (e.g. manual
        // entry, Setup & Rules), where DataTables' own sort engine is fully disabled, not just its
        // UI. Building a sort-arrow icon there anyway would look clickable but silently do nothing
        // when clicked -- checked once per table via `oFeatures.bSort` (the same internal flag
        // DataTables' own sort-related code branches on).
        const tableSortingEnabled = !!(dt.settings()[0].oFeatures && dt.settings()[0].oFeatures.bSort);
        (options.columns || []).forEach(function (col) {
            col.mode = options.mode;
            col.fetchValues = options.fetchValues;
            col.onApply = options.onApply;
            state.columns[col.key] = col.index;
            state.columns[col.key + '__config'] = col;
            const $th = $(dt.column(col.index).header());
            if ($th.find('.tcf-filter-btn').length) return; // already wired (e.g. a redraw re-ran init)
            // 2026-08-27, explicit follow-up round 2: co-opting DataTables' OWN internal header markup
            // (`div.dt-column-header` > `.dt-column-title` + `.dt-column-order`) turned out unreliable
            // for this table's actual rendered structure (screenshot showed the filter icon stacked
            // BELOW the title on its own line, no sort arrow at all) -- rather than keep guessing at
            // why that wrapper wasn't behaving as its own stylesheet describes, this now builds the
            // ENTIRE header cell's markup itself, from scratch, so the layout never depends on
            // DataTables' internal structure existing in any particular shape. The sort arrow is a
            // FRESH `<span class="dt-column-order">` (empty) -- reusing that exact class name is what
            // makes dataTables.bootstrap5.css's existing `th.dt-orderable-asc .dt-column-order:before`
            // arrow rendering apply to it automatically, with zero new CSS needed for the arrow itself;
            // it doesn't need to be the SAME element DataTables originally created, just carry the same
            // class, since that rule is a descendant selector off the `<th>`'s own order-state classes
            // (which DataTables keeps managing regardless of what's inside).
            const columnSettings = dt.settings()[0].aoColumns[col.index];
            const columnSortable = tableSortingEnabled && !!(columnSettings && columnSettings.bSortable);
            const titleText = $th.text().trim();
            const $right = $('<span class="tcf-header-right"></span>');
            if (columnSortable) {
                $right.append($('<span class="dt-column-order"></span>'));
            }
            $right.append(`<button type="button" class="tcf-filter-btn" data-tcf-key="${col.key}" title=""><i class="fa-solid fa-filter"></i></button>`);
            $th.empty()
                .addClass('tcf-th')
                .append(
                    $('<div class="tcf-header-row"></div>').append(
                        $('<span class="dt-column-title tcf-header-title"></span>').text(titleText).attr('title', titleText),
                        $right
                    )
                );
            // 2026-08-27, explicit follow-up: "ตอนนี้กดทั้ง th แล้ว sort ไม่ต้องการให้กดที่ icon sort
            // ค่อย sort" -- `data-dt-order="icon-only"` excludes this `<th>` from DataTables' own
            // default whole-cell click-to-sort delegated handler (a live jQuery selector, re-evaluated
            // on every click against the CURRENT DOM, so setting it after init still works correctly).
            // `order.listener()` -- DataTables' own public API for "bind sorting to THIS one element
            // instead" -- then wires the freshly-built sort-arrow span back up as the only click target
            // for this column. Both skipped entirely when this column/table isn't actually sortable
            // (see `tableSortingEnabled`/`columnSortable` above) -- nothing to wire, and leaving
            // `data-dt-order` off keeps this `<th>` out of DataTables' own no-op-anyway default handler.
            if (columnSortable) {
                $th.attr('data-dt-order', 'icon-only');
                dt.order.listener($th.find('.dt-column-order'), col.index);
            }
        });
    };
})();
