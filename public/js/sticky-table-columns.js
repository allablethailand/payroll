/**
 * Sticky left/right columns for a wide, horizontally-scrollable table.
 *
 * 2026-09-08, explicit request: "อยากให้ column รหัสพนักงาน และชื่อพนักงาน fixed อยู่กับที่ฝั่งซ้าย...และ
 * column Action อยากให้ fixed อยู่ขวาตลอด ส่วน Column ส่วนกลางๆ อยากให้ใช้เมาส์เลื่อนดูข้อมูลได้...ทดลองกับ
 * ตารางนี้ก่อน แค่จะมีอีกหลายตารางที่ปรับให้เป็นรูปแบบนี้" -- first consumer is the Employee "Recheck Data"
 * table (public/js/employee/list.js's initEmployeeRecheckTable()); built as a shared, reusable
 * helper (not a one-off) since more tables are expected to move to this same pattern.
 *
 * NOT built on DataTables' own FixedColumns extension -- that is confirmed BROKEN in this app
 * (see layout/footer.php's own 2026-08-31 comment): the installed `datatables.net@2.3.8` classic
 * jQuery UMD build never defines `DataTable.Dom`, which `datatables.net-fixedcolumns@6.0.0` reads
 * at load time -- it throws immediately and has never actually frozen a column anywhere in this app.
 *
 * ALSO not built on DataTables' own core `scrollX` option (the first version of this file's own
 * caller tried that) -- `scrollX` needs `.dataTables_scrollBody { overflow-x:auto; }` and friends,
 * which live in the BASE `datatables.net` skin's own CSS. This app only ever installed/loaded the
 * `datatables.net-bs5` SKIN on top of that base skin, never the base skin itself (confirmed by
 * grepping the installed `node_modules` CSS directly) -- so `scrollX` had no actual scrollable
 * container to create, and dragging to scroll silently did nothing.
 *
 * Instead: plain CSS `position: sticky` inside an ORDINARY Bootstrap `.table-responsive` wrapper
 * (`overflow-x: auto`) -- this app's own already-established, already-working convention for a wide
 * table (see CLAUDE.md/style.css's own "no DataTables scrollX, just .table-responsive" note). Since
 * there's no scrollHead/scrollBody split to juggle (that's a `scrollX`-only DataTables mechanism),
 * this only ever touches the ONE real `<table>` element -- just enough JS to MEASURE each frozen
 * column's real rendered width (content-driven, can't be hardcoded) so the 2nd+ left column and the
 * right column(s) line up flush against their neighbor instead of guessing a fixed pixel offset.
 *
 * Usage (paired with initTableDragScroll() below -- see that function's own docblock for why the
 * `.table-responsive` wrapper is added dynamically in `initComplete`, NOT written into the view's
 * own static HTML):
 *   const dt = $('#tb_x').DataTable({
 *       drawCallback: function () { initStickyColumns('#tb_x', { left: 2, right: 1 }); },
 *       initComplete: function () { initTableDragScroll('#tb_x'); },
 *   });
 *
 * `left`/`right` count columns from their respective edge (1-indexed, e.g. left:2 freezes columns 1
 * and 2). Re-run this on every redraw (drawCallback, which also covers the very first draw) since a
 * serverSide table's rendered column widths can shift between pages/filters -- cheap to recompute,
 * no caching. Works whether or not the table has been wrapped in `.table-responsive` yet -- it only
 * ever touches the table's own thead/tbody/tfoot cells, never its ancestors. A real `<tfoot>` totals
 * row (same column count as the header) is frozen the same as the header/body -- see the
 * "footHasRealColumns" guard below.
 */
/**
 * Wraps a DataTable's own `<table>` element in a plain Bootstrap `.table-responsive` div (unless
 * already wrapped) and adds real click-and-hold-then-drag horizontal panning on top of it -- plain
 * `overflow-x:auto` by itself only ever supports dragging the scrollbar thumb itself or shift+wheel,
 * never a direct click-and-drag anywhere on the table body, which is what was explicitly asked for.
 *
 * 2026-09-08, 3rd round of the same request -- MUST be called from a DataTables `initComplete`
 * callback (fires exactly ONCE, right after the table's OWN length/search/info/pagination controls
 * have already been built as siblings of the `<table>` element) -- NOT from a static wrapper written
 * into the view's own HTML, and not from `drawCallback` (which re-fires on every redraw and would
 * try to re-wrap an already-wrapped table). Wrapping too early (in the static view, before
 * DataTables initializes) was tried first and was wrong: DataTables inserts its own generated
 * controls as siblings of the table WITHIN THE TABLE'S CURRENT PARENT at init time, so a wrapper put
 * there ahead of time ends up containing (and thus horizontally scrolling) the search box/length
 * menu/pagination too, not just the columns -- confirmed live, not guessed.
 *
 * Usage: `$('#tb_x').DataTable({ initComplete: function () { initTableDragScroll('#tb_x'); } });`
 *
 * @param extraWrapClasses optional -- extra CSS classes (e.g. 'p-3 pt-2') to add ONLY when this call
 *   is the one creating the wrapper fresh (a table that already sits inside a pre-existing
 *   `.table-responsive` keeps whatever classes that div already had -- this is never applied there).
 */
function initTableDragScroll(tableSelector, extraWrapClasses) {
    const $table = $(tableSelector);
    if (!$table.length) return;
    let $wrap = $table.parent();
    // A table that already sits inside a plain `.table-responsive` div (written into the view's own
    // static HTML, e.g. a table with no sticky columns that just needed ordinary horizontal overflow)
    // just gets the drag-scroll marker class added to that SAME div -- wrapping again would nest a
    // 2nd `.table-responsive` inside the first, which works but is needless DOM noise.
    if (!$wrap.hasClass('table-responsive')) {
        $wrap = $table.wrap(`<div class="table-responsive ${extraWrapClasses || ''}"></div>`).parent();
    }
    $wrap.addClass('tbl-drag-scroll');
    if ($wrap.data('tbl-drag-scroll-bound')) return; // already wired (defensive -- initComplete itself only ever fires once per table)
    $wrap.data('tbl-drag-scroll-bound', true);

    const el = $wrap[0];
    let dragging = false;
    let moved = false;
    let startX = 0;
    let startScrollLeft = 0;

    $wrap.on('mousedown', function (e) {
        // Never hijack a genuine click on an interactive control (Edit/Delete/etc. inside the Actions
        // column, a select, a text field) -- only empty table area/cell text starts a drag.
        if ($(e.target).closest('button, a, input, select, textarea, .btn').length) return;
        dragging = true;
        moved = false;
        startX = e.pageX;
        startScrollLeft = el.scrollLeft;
        $wrap.addClass('tbl-drag-scroll-active');
    });
    $(document).on('mousemove.tblDragScroll', function (e) {
        if (!dragging) return;
        const delta = e.pageX - startX;
        // A tiny, accidental mouse jiggle during a plain click should never count as a drag (would
        // otherwise make ordinary clicks feel unreliable) -- only once real movement is seen does this
        // start actually scrolling and suppressing native text selection.
        if (!moved && Math.abs(delta) < 4) return;
        moved = true;
        e.preventDefault();
        el.scrollLeft = startScrollLeft - delta;
    });
    $(document).on('mouseup.tblDragScroll', function () {
        dragging = false;
        $wrap.removeClass('tbl-drag-scroll-active');
    });
}

function initStickyColumns(tableSelector, options = {}) {
    const left = options.left || 0;
    const right = options.right || 0;
    const $table = $(tableSelector);
    if (!$table.length || (!left && !right)) return;

    const $headCells = $table.find('> thead > tr').first().find('> th');
    const totalCols = $headCells.length;
    if (!totalCols) return;

    // A server-side table's "no matching records" state renders ONE <td colspan="N"> instead of N
    // real cells -- nth-child styling would land on the wrong (only) cell in that row, so body
    // styling is skipped entirely whenever the row shape doesn't match the header's own column count
    // (the header itself is still styled either way, so an empty table's frozen columns still look
    // right once real rows come back).
    const $firstBodyRow = $table.find('> tbody > tr').first();
    const bodyHasRealColumns = $firstBodyRow.length > 0 && $firstBodyRow.find('> td').length === totalCols;
    // 2026-09-08, real bug found and fixed (explicit report: "footer summary คำว่ารวมอยากให้ fixed ครับ
    // ตอนนี้เลื่อนตาม") -- a table with a real totals row (<tfoot>, e.g. Annual Income Summary's own
    // "Total" row) never had ITS cells included in the sticky treatment at all, so the "Total" label
    // scrolled away with the rest of the row instead of staying pinned under its own header. Same
    // colspan guard as the body above -- most tables on this pattern have no <tfoot> at all, in which
    // case this is just an empty, harmless no-op.
    const $firstFootRow = $table.find('> tfoot > tr').first();
    const footHasRealColumns = $firstFootRow.length > 0 && $firstFootRow.find('> td').length === totalCols;

    $table.find('.tbl-sticky-col')
        .removeClass('tbl-sticky-col tbl-sticky-col-edge-left tbl-sticky-col-edge-right')
        .css({ position: '', left: '', right: '', zIndex: '' });

    const leftCount = Math.min(left, totalCols);
    const rightCount = Math.min(right, totalCols - leftCount);

    function styleColumn(colIndex, cssProps, isEdge, edgeClass) {
        let $cells = $table.find(`> thead > tr > th:nth-child(${colIndex})`);
        if (bodyHasRealColumns) $cells = $cells.add($table.find(`> tbody > tr > td:nth-child(${colIndex})`));
        if (footHasRealColumns) $cells = $cells.add($table.find(`> tfoot > tr > td:nth-child(${colIndex})`));
        $cells.addClass('tbl-sticky-col').css(cssProps);
        if (isEdge) $cells.addClass(edgeClass);
    }

    let leftOffset = 0;
    for (let i = 1; i <= leftCount; i++) {
        styleColumn(i, { position: 'sticky', left: leftOffset + 'px', zIndex: 3 }, i === leftCount, 'tbl-sticky-col-edge-left');
        leftOffset += $headCells.eq(i - 1).outerWidth() || 0;
    }
    let rightOffset = 0;
    for (let i = 0; i < rightCount; i++) {
        const colIndex = totalCols - i; // 1-based from the end
        styleColumn(colIndex, { position: 'sticky', right: rightOffset + 'px', zIndex: 3 }, i === 0, 'tbl-sticky-col-edge-right');
        rightOffset += $headCells.eq(colIndex - 1).outerWidth() || 0;
    }
}
