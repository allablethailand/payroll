<?php
// design:clean -- docs/design/rules.md §12, Round 2 item 8. Passes scripts/check-design.php with 0 hits.
/**
 * Calendar widget -- docs/design/rules.md §14, Round 2 item 9.
 *
 * A plain month-grid calendar with up to 4 tone-coded event dots per day and a "selected day"
 * detail panel below -- built to the SAME visual/structural blueprint as the real dashboard
 * calendar (`app/views/dashboard.php`'s `#dashCalendarSection` + `public/js/dashboard.js`'s
 * `renderDashboardCalendarGrid()`), but under an entirely NEW class namespace (`.calendar-widget-*`,
 * not `.dash-calendar-*`) since this round does not touch real page templates (§13) -- migrating
 * the real dashboard onto this shared component is a round-4 decision, tracked in
 * docs/design/audit.md's 2026-09-13 addendum (which also flags 2 real color bugs on the live
 * dashboard/reports pages this component does NOT repeat: an indigo/blue probation dot that should
 * be gray per §3, and 2 rainbow-per-bucket report charts).
 *
 * JS twin: renderCalendarWidget(el, {month, events, onSelect}) in app.js renders the byte-equivalent
 * markup client-side AND owns month-navigation/day-selection interactivity (this PHP partial is a
 * single static snapshot for one given month -- it has no nav/click behavior of its own, same
 * "server partial = one frame, JS twin = the live version" split as status-stepper.php/timeline.php).
 *
 * @var int   $cwYear          Required. 4-digit year of the month being shown.
 * @var int   $cwMonth         Required. 1-12.
 * @var string $cwMonthLabel   Required. Caller-resolved display label (e.g. "กันยายน 2026") -- this
 *                              partial has no i18n/month-name awareness of its own (same convention
 *                              as every other partial in this file).
 * @var string[] $cwWeekdayLabels Required. Exactly 7 short weekday labels, Sunday-first (matching
 *                              `new Date().getDay()`'s own 0=Sunday convention the JS twin uses).
 * @var array $cwEvents        Required. Flat list of:
 *     ['date' => 'Y-m-d', 'tone' => 'danger'|'warning'|'success'|'muted', 'label' => string]
 *   Tone meaning is fixed, not per-caller (§14): danger=holiday, warning=payroll cutoff,
 *   success=payment date, muted=probation/internship end -- a gray, deliberately NOT `--c-info`
 *   colored dot (§3: "info = เทา ไม่ใช่ฟ้า" already rules out blue; muted here just means "the least
 *   urgent of the 4 tones", not a new status meaning).
 * @var array $cwLegend        Required. Ordered list of ['tone' => string, 'label' => string] for
 *                              the legend row below the grid -- caller supplies labels (i18n) and
 *                              controls order; this partial does not assume the 4 known types.
 * @var string|null $cwSelectedDate  Optional. 'Y-m-d' of the day to render as selected + whose
 *                              events populate the detail panel below the grid. Null = no selection,
 *                              renders the detail panel's empty state.
 * @var string $cwEmptyDetailText  Optional, default 'เลือกวันที่เพื่อดูรายละเอียด'. Caller-resolved
 *                              (i18n) empty-state text for the detail panel when nothing is selected.
 *
 * Visual rules (§14):
 * - Day cell: background --c-bg, border --c-border, radius --radius. Today: background
 *   --c-primary-soft (no border change). Selected: 2px --c-primary border (stacks with today's own
 *   soft background if a day is both). Days outside the month / trailing blanks render NO box at all
 *   (an empty grid cell, not a faded day number -- this widget's grid is always exactly the
 *   requested month, it never shows adjacent-month days the way a date-PICKER does).
 * - Event dots: up to 3 shown per day even if more exist (a day with 4 events still shows only 3
 *   dots -- the detail panel is where the full list lives).
 * - Month-select control: a plain native <select> (`.calendar-widget-month-select`, tertiary text
 *   styling, `--c-text-muted` + a chevron) -- no colored "live/current" dot (the real dashboard's
 *   own `.dash-period-picker-live-dot` green dot has no equivalent here on purpose, it doesn't carry
 *   real meaning per §1 "สีต้องตอบ 2 คำถาม").
 * - "ปฏิทิน" heading: plain text, no icon at all (§2 -- an icon here would be pure decoration, not
 *   information).
 *
 * Example:
 *   $cwYear = 2026; $cwMonth = 9; $cwMonthLabel = 'กันยายน 2026';
 *   $cwWeekdayLabels = ['อา','จ','อ','พ','พฤ','ศ','ส'];
 *   $cwEvents = [['date' => '2026-09-13', 'tone' => 'danger', 'label' => 'วันหยุดชดเชย']];
 *   $cwLegend = [['tone' => 'danger', 'label' => 'วันหยุด'], ['tone' => 'warning', 'label' => 'วันตัดรอบ'],
 *               ['tone' => 'success', 'label' => 'วันจ่ายเงิน'], ['tone' => 'muted', 'label' => 'สิ้นสุดทดลองงาน/ฝึกงาน']];
 *   $cwSelectedDate = '2026-09-13';
 *   include __DIR__ . '/../partials/calendar-widget.php';
 */
$cwEventsByDate = [];
foreach ($cwEvents as $cwEvent) {
    $cwEventsByDate[$cwEvent['date']][] = $cwEvent;
}
$cwDaysInMonth = (int)date('t', mktime(0, 0, 0, $cwMonth, 1, $cwYear));
$cwStartWeekday = (int)date('w', mktime(0, 0, 0, $cwMonth, 1, $cwYear)); // 0 = Sunday
$cwTodayStr = date('Y-m-d');
$cwCells = array_fill(0, $cwStartWeekday, null);
for ($cwD = 1; $cwD <= $cwDaysInMonth; $cwD++) {
    $cwCells[] = $cwD;
}
while (count($cwCells) % 7 !== 0) {
    $cwCells[] = null;
}
$cwSelectedEvents = $cwSelectedDate ? ($cwEventsByDate[$cwSelectedDate] ?? []) : [];
?>
<div class="calendar-widget">
    <div class="calendar-widget-nav">
        <button type="button" class="calendar-widget-nav-btn" aria-label="Previous month"><i class="fa-solid fa-chevron-left"></i></button>
        <span class="calendar-widget-month-select-wrap">
            <select class="calendar-widget-month-select" aria-label="เลือกเดือน">
                <option selected><?=htmlspecialchars($cwMonthLabel)?></option>
            </select>
            <i class="fa-solid fa-chevron-down calendar-widget-month-select-caret"></i>
        </span>
        <button type="button" class="calendar-widget-nav-btn" aria-label="Next month"><i class="fa-solid fa-chevron-right"></i></button>
    </div>
    <div class="calendar-widget-grid">
        <div class="calendar-widget-row calendar-widget-header-row">
            <?php foreach ($cwWeekdayLabels as $cwWd): ?>
                <div class="calendar-widget-cell calendar-widget-weekday"><?=htmlspecialchars($cwWd)?></div>
            <?php endforeach; ?>
        </div>
        <?php foreach (array_chunk($cwCells, 7) as $cwRow): ?>
            <div class="calendar-widget-row">
                <?php foreach ($cwRow as $cwDay):
                    if ($cwDay === null) {
                        echo '<div class="calendar-widget-cell calendar-widget-cell-empty"></div>';
                        continue;
                    }
                    $cwDateStr = sprintf('%04d-%02d-%02d', $cwYear, $cwMonth, $cwDay);
                    $cwDayEvents = $cwEventsByDate[$cwDateStr] ?? [];
                    $cwIsToday = $cwDateStr === $cwTodayStr;
                    $cwIsSelected = $cwSelectedDate !== null && $cwDateStr === $cwSelectedDate;
                    $cwCellClass = 'calendar-widget-cell calendar-widget-day';
                    if ($cwIsToday) $cwCellClass .= ' calendar-widget-today';
                    if ($cwIsSelected) $cwCellClass .= ' calendar-widget-selected';
                ?>
                    <div class="<?=$cwCellClass?>" data-date="<?=$cwDateStr?>">
                        <span class="calendar-widget-day-num"><?=$cwDay?></span>
                        <?php if ($cwDayEvents): ?>
                            <div class="calendar-widget-dots">
                                <?php foreach (array_slice($cwDayEvents, 0, 3) as $cwEv): ?>
                                    <span class="calendar-widget-dot calendar-widget-dot-<?=htmlspecialchars($cwEv['tone'])?>"></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="calendar-widget-legend">
        <?php foreach ($cwLegend as $cwLg): ?>
            <span class="calendar-widget-legend-item">
                <span class="calendar-widget-dot calendar-widget-dot-<?=htmlspecialchars($cwLg['tone'])?>"></span><?=htmlspecialchars($cwLg['label'])?>
            </span>
        <?php endforeach; ?>
    </div>
    <div class="calendar-widget-detail">
        <?php if ($cwSelectedDate && $cwSelectedEvents): ?>
            <div class="calendar-widget-detail-date"><?=htmlspecialchars($cwSelectedDate)?></div>
            <?php foreach ($cwSelectedEvents as $cwEv): ?>
                <div class="calendar-widget-detail-row">
                    <span class="calendar-widget-dot calendar-widget-dot-<?=htmlspecialchars($cwEv['tone'])?>"></span><?=htmlspecialchars($cwEv['label'])?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="calendar-widget-detail-empty"><?=htmlspecialchars($cwEmptyDetailText ?? 'เลือกวันที่เพื่อดูรายละเอียด')?></div>
        <?php endif; ?>
    </div>
</div>
