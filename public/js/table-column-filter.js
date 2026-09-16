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

    // 2026-09-13, Round 3 "เก็บตก" item 2, real gaps found and fixed (this popup was built 2026-08-27,
    // before Phase Design Round 2/3 even started -- confirmed via git history/its own top-of-file date
    // -- so it never got migrated to the shared component vocabulary at all, unlike everything else
    // touched this round):
    //   (b) `.tcf-select-all`/`.tcf-value-cb` had NO class beyond their own -- true native unstyled
    //       browser checkboxes (confirmed, not just "using a custom style" -- there was no CSS rule
    //       for either at all). `.form-check-input` (Bootstrap's own class, already overridden orange
    //       app-wide -- style.css's `.form-check-input:checked` rule) needs no `.form-check` wrapper
    //       to render correctly here since `.tcf-item`'s own layout is a plain flex row with `gap`, not
    //       Bootstrap's own margin-left:-1.5em positioning trick that DOES require that wrapper (the
    //       exact bug class already found once this session in the Payslip/ECT Assign-To checkboxes --
    //       confirmed NOT applicable here before relying on it).
    //   (c) close button becomes a real `.btn-icon.btn-icon-ghost` (§7) -- `.tcf-panel-close` itself
    //       keeps NO visual CSS of its own anymore (see style.css), just enough to sit correctly in the
    //       header's flex row; kept as a class here too so the existing `$(document).on('click',
    //       '.tcf-panel-close', ...)` delegated handler needs no change.
    // 2026-09-14, Round 3 "เก็บตกรอบ 5" item 1, REAL ROOT CAUSE found (explicit report: this is the
    // 4th round in a row where this popup's text stayed English no matter what) -- every label lookup
    // in this file used `(window.langData && langData['key'])`, guarding on `window.langData`
    // specifically. `app.js` declares its own `langData` with `let langData = {}` at the TRUE top
    // level of a plain classic `<script>` (not `type="module"`, no wrapping IIFE) -- a `let`/`const`
    // declared that way creates a binding in the shared global LEXICAL scope (visible to every other
    // `<script>` on the page as a bare `langData` reference, confirmed this is exactly how
    // `getLangValue()`/every other file in this app already reads it) but it is NEVER attached to the
    // `window` OBJECT the way a `var` or bare `function` declaration would be -- confirmed directly:
    // `typeof window.langData` is `'undefined'` always, unconditionally, regardless of whether
    // `langData` itself is genuinely populated. That means `window.langData && ...` short-circuited
    // to `undefined` on EVERY single call, in EVERY language, before ever reaching the real
    // `langData['key']` lookup that WOULD have worked -- every previous round's fix (moving the lookup
    // to open-time, adding new lang keys) was necessary but could never have worked while this guard
    // stayed broken underneath it. No other file in this app uses this `window.langData &&` pattern
    // (confirmed via grep) -- every other consumer already uses the correct, established
    // `langData['key'] || 'fallback'` (bare reference) or `getLangValue('key')`. Fixed by switching
    // every lookup below to `getLangValue()` (app.js's own canonical helper, reads the bare `langData`
    // directly, exactly what every other i18n consumer in this app already calls) instead of
    // reinventing an equivalent inline. Also renamed the 3 popup-specific keys this file owns
    // (`column_filter_title/_clear/_apply` -> `dt_filter_title/_clear/_apply`) and stopped reusing the
    // shared generic `search`/`select_all` keys, giving this popup its OWN complete, independent set
    // of 5 keys (`dt_filter_title/_search/_select_all/_clear/_apply`, public/lang/*.json) -- one
    // consistent prefix, fully decoupled from any other page's wording (same "don't couple unrelated
    // call sites through a shared key" reasoning `column_filter_clear`/`column_filter_apply` already
    // established last round, just completed for the other 2 that were still borrowed).
    function ensurePanel() {
        if ($panel) return $panel;
        $panel = $(`
            <div class="tcf-panel d-none">
                <div class="tcf-panel-header">
                    <span class="tcf-panel-title"></span>
                    <button type="button" class="btn-icon btn-icon-ghost tcf-panel-close" title=""><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="tcf-search-wrap">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" class="tcf-search" placeholder="">
                </div>
                <div class="tcf-select-all-row">
                    <label class="tcf-item">
                        <input type="checkbox" class="tcf-select-all form-check-input" checked>
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
        applyPanelLabels();
        return $panel;
    }
    // 2026-09-13, Round 3 "เก็บตกรอบ 4", real bug found and fixed (explicit report: switching language
    // did NOT update this popup's own text) -- the 6 label/placeholder lines used to live INSIDE
    // ensurePanel(), which only ever runs its body ONCE (the shared `$panel` node is built a single
    // time then reused for every table/column, per this file's own top-of-file docblock) -- so
    // whatever language was active the FIRST time any column filter was ever opened stuck permanently
    // after that, regardless of later language switches. Extracted so `openPanelFor()` below can call
    // it fresh on every single open (`langData` read live, never cached) -- "render สดใน open handler
    // ไม่ cache ข้อความตอน init", per the explicit instruction. Also called once from ensurePanel()
    // itself so the panel is never actually left with its raw HTML placeholders even in the split
    // second before the first real open call runs it again.
    function applyPanelLabels() {
        $panel.find('.tcf-panel-title').text(getLangValue('dt_filter_title') || 'Filter');
        $panel.find('.tcf-panel-close').attr('title', getLangValue('close') || 'Close');
        $panel.find('.tcf-search-wrap input').attr('placeholder', getLangValue('dt_filter_search') || 'Search...');
        $panel.find('.tcf-select-all-label').text(getLangValue('dt_filter_select_all') || 'Select All');
        $panel.find('.tcf-clear-btn').text(getLangValue('dt_filter_clear') || 'Clear');
        $panel.find('.tcf-apply-btn').text(getLangValue('dt_filter_apply') || 'Apply Filter');
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
    // output instead -- exactly what DataTables' own built-in global search already keys off by
    // default -- fixes this generically for every column shape without needing a per-table special
    // case. Rendered output is frequently HTML (badges, `<strong>` wrappers, icons) rather than plain
    // text, so it's stripped through a detached DOM node before being used as either a checkbox label
    // or a filter-match key.
    // 2026-09-14, Round 3 "เก็บตกรอบ 5" item 2, real bug found and fixed (explicit report: the
    // "ตรวจสอบ" column's own filter list showed "ยกเลิกการตรวจสอบ" -- the verified badge's own hidden
    // ⋮-menu item text, not a real filter value) -- was `.render('display')`, which for ANY column
    // whose own render option is a plain function (no `{display, filter}` object-form split) returns
    // the exact SAME HTML the user sees on screen, menus and all, since DataTables falls back to that
    // one function for every render type when no split is given. Switched to `.render('filter')` --
    // DataTables' own dedicated "the value to use for filtering" render type -- which correctly picks
    // up a column's own `filter` variant when one is configured (payroll/detail.js's Verify/Lock
    // column now has one, see verifyLockFilterTextRd()) and transparently falls back to the SAME
    // single function as before for every OTHER column that hasn't split display/filter apart (no
    // behavior change for those -- confirmed this is how DataTables' own `.render(type)` API always
    // behaves for a plain, non-object `render` option, not assumed).
    function stripHtml(html) {
        if (html === null || html === undefined) return '';
        const div = document.createElement('div');
        div.innerHTML = String(html);
        return div.textContent.trim();
    }
    function renderedCellText(dt, rowIndex, colIndex) {
        let out;
        try {
            out = dt.cell(rowIndex, colIndex).render('filter');
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
                    $('<input type="checkbox" class="tcf-value-cb form-check-input">').val(val).prop('checked', checked),
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
        applyPanelLabels(); // read langData live on every open -- see this function's own docblock
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
        panel.find('.tcf-list').html(`<div class="tcf-loading text-secondary small py-2 text-center"><i class="fa-solid fa-spinner fa-spin me-1"></i>${getLangValue('loading') || 'Loading...'}</div>`);

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

    /** Public: is anything narrowed by a column filter right now? (app.js's own empty-state and
     *  "clear everything" paths ask this -- they must not claim "no filter is active" while a column
     *  checklist is still narrowing the table.) */
    window.hasActiveColumnFilters = function (dt) {
        if (!dt || typeof dt.settings !== 'function') return false;
        return Object.keys(tableState(dt).selected).length > 0;
    };
    /** Public: drop every column filter on this table and redraw/reload once. Returns whether
     *  anything was actually cleared, so a caller can decide whether it still needs its own redraw.
     *  Server-mode tables carry their selections in `ajax.data` (getColumnFilterValues()), so they
     *  need a reload rather than a redraw -- the mode is read back off the columns' own stored
     *  config rather than asked for again by the caller. */
    window.clearColumnFilters = function (dt) {
        if (!dt || typeof dt.settings !== 'function') return false;
        const state = tableState(dt);
        const keys = Object.keys(state.selected);
        if (!keys.length) return false;
        keys.forEach(function (key) {
            delete state.selected[key];
            updateFilterBtnState(dt, key);
        });
        const serverMode = Object.keys(state.columns).some(function (k) {
            return k.slice(-8) === '__config' && (state.columns[k] || {}).mode === 'server';
        });
        if (serverMode) dt.ajax.reload(null, false); else dt.draw();
        return true;
    };

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
            // 2026-09-13, Round 3 "เก็บตกรอบ 4", real bug found and fixed (explicit repro: switch
            // language TH->EN->TH, sort-arrow + filter icon vanish from every column header this
            // function has already wired up). Root cause: the view's own static markup puts
            // `data-i18n="{key}"` directly on the `<th>` itself (e.g. `<th data-i18n="table_calculation">
            // Calculation</th>`) -- this function empties and rebuilds the `<th>`'s CHILDREN right
            // below, but never touched the `<th>` element's OWN attributes, so that `data-i18n` stayed
            // on it. app.js's own updateText() (the central language sweep) finds EVERY `[data-i18n]`
            // element on the page on each language switch and, for one with no `> i`/`> svg` direct
            // child (true of this `<th>` post-rebuild -- its real children are now nested inside a
            // `.tcf-header-row` div, not a bare icon), falls through to a plain `.text(value)` call --
            // which, exactly like `.html()`, wipes out EVERY child node first. That silently destroyed
            // the sort-arrow span + filter button this function had just built, replacing the whole
            // `<th>` with a bare translated text node -- confirmed by reading updateText()'s own
            // branches directly, not guessed. Fixed at the source: capture the `<th>`'s own
            // `data-i18n` (if any) BEFORE rebuilding, strip it from the `<th>` itself, and move it onto
            // the new `.tcf-header-title` span instead -- that span is a true leaf (no children of its
            // own), so updateText()'s EXISTING plain-text branch updates it correctly on every future
            // language switch without ever touching the sort-arrow/filter button siblings again. No
            // change needed in updateText() itself for this -- the sweep's own per-element logic was
            // already correct for a genuine leaf element, the bug was purely that the wrong element
            // (the `<th>`, not its label span) carried the marker after this function's own rebuild.
            // 2026-09-14, Round 3 "เก็บตกรอบ 7" follow-up, real gap found in the fix above: it only ever
            // read `$th.attr('data-i18n')` -- correct for the 4 payroll/detail.js columns THIS function
            // had already audited (their markup put `data-i18n` directly on the `<th>`), but that markup
            // pattern was itself found to be the wrong app-wide convention the same day (see detail.php's
            // own 2026-09-14 docblock on its `<thead>`): DataTables' OWN header-construction routine
            // (`node_modules/datatables.net/js/dataTables.js`) ALWAYS moves a header cell's existing
            // children into a fresh `.dt-column-title` wrapper it builds, for every `<th>`, whether this
            // function ever touches that column or not -- so the app-wide convention going forward is
            // `data-i18n` on a plain inner `<span>`, never the `<th>` itself. `$th.attr('data-i18n')`
            // alone would now find nothing on any column using the corrected markup, silently dropping
            // the `data-i18n` marker the moment this function rebuilds the cell. Falls back to the first
            // descendant `[data-i18n]` element when the `<th>` itself doesn't carry one, so both markup
            // shapes keep working -- the still-current `<th data-i18n="...">` pattern (any other caller
            // that hasn't been migrated yet) and the corrected `<th><span data-i18n="...">` pattern.
            const titleI18nKey = $th.attr('data-i18n') || $th.find('[data-i18n]').first().attr('data-i18n');
            $th.removeAttr('data-i18n');
            const $right = $('<span class="tcf-header-right"></span>');
            if (columnSortable) {
                $right.append($('<span class="dt-column-order"></span>'));
            }
            // 2026-09-13, Round 3 "เก็บตกรอบ 4", explicit instruction: "ใช้ fa-filter ตัวเดียวกับ filter-bar
            // (ไม่ใช่ ▾)" -- REVERTS the previous "เก็บตก" round's own change to fa-chevron-down (that
            // round read "▼" as literally wanting a caret glyph; this round's explicit correction is
            // the opposite -- match filter-bar.php's own `.filter-bar-label-icon` glyph choice exactly,
            // fa-filter, not a chevron/caret at all). Size/color unchanged by this revert -- 0.9167rem
            // already equals 11px at Medium (this rule's own font-size below), --c-text-faint resting/
            // --c-primary active were already correct from the previous round.
            $right.append(`<button type="button" class="tcf-filter-btn" data-tcf-key="${col.key}" title=""><i class="fa-solid fa-filter"></i></button>`);
            const $titleSpan = $('<span class="dt-column-title tcf-header-title"></span>').text(titleText).attr('title', titleText);
            if (titleI18nKey) $titleSpan.attr('data-i18n', titleI18nKey);
            $th.empty()
                .addClass('tcf-th')
                .append(
                    $('<div class="tcf-header-row"></div>').append(
                        $titleSpan,
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
