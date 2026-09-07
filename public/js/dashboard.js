function dashEscapeHtml(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}

function dashFmtNum(n) {
    return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function dashToDisplayDate(isoVal) {
    if (!isoVal) return '';
    const parts = String(isoVal).split('-');
    if (parts.length !== 3) return isoVal;
    const [yyyy, mm, dd] = parts;
    return `${dd}/${mm}/${yyyy}`;
}

// 2026-08-29, explicit request (this round): "ตรง Recent Payroll Runs ที่เห็น...ให้แสดงผลเป็น timeline
// เหมือนใน Process List รายการครับ" -- an EARLIER same-day pass already replaced the plain state
// badge with a mini timeline, but built it against the wrong reference component (Process List's
// top FILTER BAR chevrons, .station-row/.station-card-sm) -- this user report is about the
// DIFFERENT widget, the dot+connecting-line mini-timeline that renders inside each ROW of the
// Process List's own table (renderMiniTimelineDots() in public/js/payroll/index.js, `.mini-timeline`/
// `.mt-dot`/`.mt-line` in style.css). Ported here verbatim (same duplicated-per-page convention that
// file's own top-of-file comment already documents -- every page's JS stays self-contained) rather
// than loading index.js on the dashboard, which would pull in a large amount of unrelated
// Process-List-only code (DataTable init, bulk actions, sync pickers, ...). No quick-action button
// here (unlike index.js's own miniTimelineQuickActionHtml()) -- the Dashboard widget is a glance
// view/link out to the real page, not a place to trigger payroll state changes from.
const DASH_MINI_TIMELINE_STEPS = [
    { key: 'draft', labelKey: 'state_draft', dateField: 'created_at', icon: 'fa-file-alt' },
    { key: 'pending_approval', labelKey: 'state_pending_approval', dateField: 'submitted_at', icon: 'fa-paper-plane' },
    { key: 'approved', labelKey: 'state_approved', dateField: 'approved_at', icon: 'fa-check' },
    { key: 'paid', labelKey: 'state_paid', dateField: 'paid_at', icon: 'fa-money-check-dollar' },
    { key: 'locked', labelKey: 'state_locked', dateField: 'locked_at', icon: 'fa-lock' },
];
const DASH_MINI_TIMELINE_BRANCH_ICONS = { rejected: 'fa-xmark', cancelled: 'fa-ban', need_info: 'fa-question' };
const DASH_MINI_TIMELINE_BRANCH_LABEL_KEYS = { rejected: 'state_rejected', cancelled: 'state_cancelled', need_info: 'state_need_info' };
function dashComputeMiniTimelineProgress(row) {
    const state = row.state;
    if (state === 'rejected') {
        return { reachedIdx: 1, branch: { atIndex: 2, type: 'rejected' } };
    }
    if (state === 'need_info') {
        return { reachedIdx: 1, branch: { atIndex: 2, type: 'need_info' } };
    }
    if (state === 'cancelled') {
        const fromKey = row.cancelled_from_state || 'draft';
        if (fromKey === 'draft') {
            return { reachedIdx: -1, branch: { atIndex: 0, type: 'cancelled' } };
        }
        const effectiveKey = fromKey === 'rejected' ? 'pending_approval' : fromKey;
        const idx = DASH_MINI_TIMELINE_STEPS.findIndex(s => s.key === effectiveKey);
        if (idx < 0) {
            return { reachedIdx: -1, branch: { atIndex: 0, type: 'cancelled' } };
        }
        return { reachedIdx: idx, branch: { atIndex: idx + 1, type: 'cancelled' } };
    }
    // 2026-08-29, real bug found and fixed (explicit report: "Locked จะเป็นสีเขียวตอนไหนครับ" -- see
    // payroll/detail.js's own computeTimelineProgress() docblock for the full explanation, ported
    // here verbatim same as this whole function already was).
    const idx = DASH_MINI_TIMELINE_STEPS.findIndex(s => s.key === state);
    return { reachedIdx: idx, branch: null };
}
function dashRunTimelineHtml(row) {
    const { reachedIdx, branch } = dashComputeMiniTimelineProgress(row);
    const currentIndex = reachedIdx + 1;
    let dotsHtml = '<ul class="mini-timeline">';
    for (let i = 0; i < DASH_MINI_TIMELINE_STEPS.length; i++) {
        const step = DASH_MINI_TIMELINE_STEPS[i];
        let cls = '';
        let label = langData[step.labelKey] || step.key;
        let icon = step.icon;
        const isBranchHere = branch && branch.atIndex === i;
        if (isBranchHere) {
            cls = branch.type;
            label = langData[DASH_MINI_TIMELINE_BRANCH_LABEL_KEYS[branch.type]] || branch.type;
            icon = DASH_MINI_TIMELINE_BRANCH_ICONS[branch.type] || 'fa-ban';
        } else if (i <= reachedIdx) {
            cls = 'done';
            icon = 'fa-check';
        } else if (i === currentIndex) {
            cls = 'current';
        }
        const dateVal = row[step.dateField];
        const dateText = (cls === 'done' || cls === 'current' || isBranchHere) && dateVal ? dashToDisplayDate(String(dateVal).substring(0, 10)) : '';
        const title = dashEscapeHtml(`${label}${dateText ? ` (${dateText})` : ''}`);
        dotsHtml += `<li class="mt-step ${cls}"><span class="mt-dot" title="${title}"><i class="fa-solid ${icon}"></i></span></li>`;
        if (i < DASH_MINI_TIMELINE_STEPS.length - 1) {
            dotsHtml += `<span class="mt-line ${i <= reachedIdx ? 'done' : ''}"></span>`;
        }
    }
    dotsHtml += '</ul>';
    return dotsHtml;
}

function dashEmployeeDisplayName(emp) {
    if (!emp) return '';
    const first = currentLang === 'en' ? (emp.name_en || emp.name_th) : (emp.name_th || emp.name_en);
    const last = currentLang === 'en' ? (emp.surname_en || emp.surname_th) : (emp.surname_th || emp.surname_en);
    return [first, last].filter(Boolean).join(' ');
}

function dashGreetingKey() {
    const h = new Date().getHours();
    if (h < 12) return 'dashboard_greeting_morning';
    if (h < 18) return 'dashboard_greeting_afternoon';
    return 'dashboard_greeting_evening';
}

// 2026-09-06, Dashboard redesign: month/year historical picker -- $year/$month both omitted (the
// default, called from $(document).ready() below with no args) reproduces the exact live/current
// view this endpoint always returned before this feature existed. Passing both switches the whole
// page into the historical lens DashboardController::summary()'s own docblock describes.
// 2026-09-07, real bug found and fixed: this used to be reachable a 2nd time per page load through
// a completely unrelated path -- app.js's own applyLanguage() (run once loadLang()'s async fetch
// resolves, which lands AFTER this file's own $(document).ready() has already fired the first,
// unconditional call below) re-inits every `.select2-native.select2-hidden-accessible` element and,
// when it already has a value, fires a genuinely PLAIN `.trigger('change')` (no namespace) on it --
// unlike `.select2-static`'s own re-init, which only ever fires the namespaced `change.select2`.
// #dashPeriodYear used to be exactly such a `.select2-native` select, so language loading alone
// (every page load, not a user action) refired this via the old `$(document).on('change',
// '#dashPeriodMonth, #dashPeriodYear', ...)` handler, showing/hiding the full-page loader TWICE in a
// row (explicit report: "ขึ้น loading 2 รอบก่อนโหลดหน้า เหมือนระบบกระตุก") -- confirmed by tracing
// $(document).ready() registration order (app.js loads first and `await`s loadLang() inside its own
// ready handler, which yields control back to jQuery so THIS file's later-registered ready handler
// still runs first) then applyLanguage()'s own native-select branch. Fixed at the root by dropping
// the plain <select>x2 entirely in favor of the plain-button month/year picker below (no select2
// anywhere in it, so applyLanguage() has nothing to re-init/re-trigger here at all) -- kept as a
// code comment, not just a git message, since the failure mode (an unrelated shared function firing
// a plain, unnamespaced `change` on an element this file also delegates a handler to) is exactly the
// kind of thing worth checking first if a similar "fires twice" report ever comes up again elsewhere.
function loadDashboardSummary(year, month) {
    // 2026-09-03, Platform UX review Phase 2 (revised): the Dashboard's own main content IS this one
    // fetch -- the clearest "page-level" case for the full-page loader (see app.js's own
    // showPageLoader()/hidePageLoader() docblock).
    if (typeof showPageLoader === 'function') showPageLoader();
    const params = {};
    if (year && month) {
        params.year = year;
        params.month = month;
    }
    $.ajax({
        url: `${BASE_URL}/api/dashboard.summary`,
        method: 'GET',
        data: params,
        dataType: 'json',
        success: function (res) {
            if (!res || !res.status) return;
            renderDashboard(res.data || {});
        },
        complete: function () {
            if (typeof hidePageLoader === 'function') hidePageLoader();
        }
    });
}

// 2026-08-29, real bug found and fixed: #dashGreetingTitle/#dashGreetingDesc used to carry
// data-i18n too (in dashboard.php) -- applyLanguage()'s generic updateText() sweep (public/js/
// app.js) runs on every language switch and just does .text(rawLangValue) for any data-i18n
// element, with no knowledge that this particular template still has an un-interpolated {date}
// placeholder in it. Since nothing re-ran renderDashboard() afterward, switching language (or even
// the very first loadLang() call racing this file's own ajax callback) could leave the raw
// "...{date}..." string on screen permanently instead of a real date. Fixed by removing data-i18n
// from both elements in the view (this function is now their ONLY writer) and by hooking this
// function back into every language change (see app.js's changeLanguage()) instead.
function renderDashboard(data) {
    const name = dashEmployeeDisplayName(data.employee);
    const greetPrefix = langData[dashGreetingKey()] || langData['dashboard_greeting_default'] || 'Welcome';
    $('#dashGreetingTitle').text(name ? `${greetPrefix}, ${name}` : greetPrefix);

    const dateStr = dashToDisplayDate(new Date().toISOString().slice(0, 10));
    const descTpl = langData['dashboard_greeting_description'] || 'Today is {date}. Here is an overview of your payroll workspace.';
    $('#dashGreetingDesc').text(descTpl.replace('{date}', dateStr));

    // 2026-09-07, Dashboard redesign: syncs the month/year picker itself to whatever the backend
    // says is authoritative (matters both on first load -- defaults to today -- and after "Back to
    // Current" resets it).
    dashSelectedYear = Number(data.selected_year);
    dashSelectedMonth = Number(data.selected_month);
    dashPickerViewYear = dashSelectedYear;
    dashUpdatePeriodPickerLabel();
    $('#dashPeriodLiveDot')
        .toggleClass('dash-period-picker-live-dot-historical', !!data.is_historical)
        .attr('title', data.is_historical ? (langData['dash_viewing_historical'] || 'Viewing historical data') : (langData['dash_viewing_period'] || 'Viewing:'));
    $('#dashPeriodBackToCurrentBtn').toggleClass('d-none', !data.is_historical);
    $('#dashCalendarTodayBtn').toggleClass('d-none', !data.is_historical);
    const periodTpl = langData['dash_period_suffix'] || ' ({month} {year})';
    const periodLabel = data.is_historical ? periodTpl.replace('{month}', langData['month_' + data.selected_month] || data.selected_month).replace('{year}', data.selected_year) : '';
    $('.dash-period-suffix').text(periodLabel);
    loadDashboardCalendar(data.selected_year, data.selected_month);
    renderDepartmentChart(data.department_headcount || []);

    const stats = data.employee_stats || {};
    $('#dashActiveEmployees').text((stats.active_count || 0).toLocaleString());
    $('#dashNewHires').text((stats.new_this_month || 0).toLocaleString());

    // 2026-08-28, explicit request: "อยากให้เห็นเหมือนกันทั้งหมด แต่ตรงตัวเลขเงินเดือนให้เป็นไปตาม Role
    // ที่ Set ไว้" -- the payroll widgets themselves (run counts/dates/Recent Runs list) now always
    // render for every employee regardless of role; only the money AMOUNT within Recent Runs is
    // conditionally shown, via can_view_payroll (see renderRecentRuns()) -- the backend
    // (DashboardController::summary()) already strips the money fields entirely from the JSON when
    // this is false, so this flag here is purely about whether to render an amount element at all,
    // not a client-side "hide the real number" -- there is no real number in the payload to hide.
    renderPayrollWidgets(data.payroll || {}, !!data.can_view_payroll);

    // 2026-09-02, explicit request (item 5 of a 5-item follow-up list): probation/internship
    // period-expiry reminder card -- key is entirely ABSENT from `data` (not an empty array) when
    // the acting employee lacks can_process_payroll or there's genuinely nothing to show, per
    // DashboardController::summary()'s own comment -- `|| []` here treats both cases identically.
    renderProbationInternExpiring(data.probation_intern_expiring || []);

    // 2026-09-04, Backlog Phase 10, T058: "Dashboard shows currently-online users."
    renderOnlineUsers(data.online_users || [], data.online_users_total || 0);

    // 2026-09-04, Backlog Phase 10, T057: the ONE admin-picked featured announcement.
    renderFeaturedAnnouncement(data.featured_announcement || null);
}

function renderFeaturedAnnouncement(row) {
    const $section = $('#dashAnnouncementSection');
    if (!row) {
        $section.addClass('d-none');
        return;
    }
    $section.removeClass('d-none');
    $('#dashAnnouncementTitle').text(currentLang === 'en' ? row.title_en : row.title_th);
    const body = (currentLang === 'en' ? row.body_en : row.body_th) || '';
    $('#dashAnnouncementBody').text(body.length > 160 ? body.slice(0, 160) + '...' : body);
}

/* ==================== First-login-after-publish click-through modal (T057) ====================
 * Checked once per Dashboard load (not from ensure_login() -- that's a security choke point, not a
 * UI trigger, see T058's own established caution about that function). Queue click-through: each
 * accept/dismiss click calls api/announcement.acknowledge then advances to the next pending one, only
 * closing once the queue is empty. accept_required=1 items are a genuine BLOCKING step (no backdrop/
 * Esc dismiss) -- a merely-dismissible one still goes through the SAME click-through queue (so the
 * remaining-count stays accurate and predictable either way) but its own backdrop/keyboard IS allowed
 * to close the modal without acknowledging (skipped, not force-acknowledged) -- re-appears next load. */
let dashAnnouncementQueue = [];
let dashAnnouncementQueueIndex = 0;
function dashCheckPendingAnnouncements() {
    $.ajax({
        url: `${BASE_URL}/api/announcement.pending-list`, method: 'GET', dataType: 'json',
        success: function (res) {
            if (res.status && res.data && res.data.length) {
                dashAnnouncementQueue = res.data;
                dashAnnouncementQueueIndex = 0;
                dashShowAnnouncementModalStep();
            }
        }
    });
}
function dashShowAnnouncementModalStep() {
    if (dashAnnouncementQueueIndex >= dashAnnouncementQueue.length) {
        bootstrap.Modal.getInstance(document.getElementById('dashAnnouncementModal'))?.hide();
        return;
    }
    const item = dashAnnouncementQueue[dashAnnouncementQueueIndex];
    const remaining = dashAnnouncementQueue.length - dashAnnouncementQueueIndex;
    $('#dashAnnModalCount').text(`${dashAnnouncementQueueIndex + 1} / ${dashAnnouncementQueue.length}`);
    $('#dashAnnModalTitle').text(currentLang === 'en' ? item.title_en : item.title_th);
    $('#dashAnnModalBody').text(currentLang === 'en' ? item.body_en : item.body_th);
    $('#dashAnnModalAcceptBtn').text(item.accept_required ? (langData['announcement_accept'] || 'Accept') : (langData['announcement_dismiss'] || 'Dismiss'));
    const modalEl = document.getElementById('dashAnnouncementModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl, {
        backdrop: item.accept_required ? 'static' : true,
        keyboard: !item.accept_required,
    });
    modal.show();
}
$(document).on('click', '#dashAnnModalAcceptBtn', function () {
    const item = dashAnnouncementQueue[dashAnnouncementQueueIndex];
    if (!item) return;
    $.ajax({
        url: `${BASE_URL}/api/announcement.acknowledge`, method: 'POST', contentType: 'application/json', dataType: 'json',
        data: JSON.stringify({ id: item.id, via: 'modal' }),
        complete: function () {
            dashAnnouncementQueueIndex++;
            dashShowAnnouncementModalStep();
        }
    });
});

// See dashboard.php's own #dashOnlineUsersSection comment. `last_seen_at` (not login_at) is what
// this renders as "since" -- it's the presence timestamp this whole feature is built around (see
// EmployeeLoginLogModel::touchLastSeen()'s own docblock), not when the session originally started.
function dashOnlineDisplayName(row) {
    const first = currentLang === 'en' ? (row.name_en || row.name_th) : (row.name_th || row.name_en);
    const last = currentLang === 'en' ? (row.surname_en || row.surname_th) : (row.surname_th || row.surname_en);
    return [first, last].filter(Boolean).join(' ') || row.employee_no || '-';
}
// 2026-09-07, explicit request: "ผู้ใช้งานออนไลน์ตอนนี้ 420 นาทีที่แล้ว แสดงแสดงเป็นชั่วโมง นาที ดีกว่าครับ"
// -- a stale "since" this far out (7 hours) is realistically a session that never got a clean
// logout (tab closed without hitting Switch App/Logout), so anything in this range genuinely can
// run into the hundreds of minutes -- past 60 it's shown as hours(+minutes) instead.
function dashRelativeMinutesAgo(isoDateTime) {
    if (!isoDateTime) return '';
    const then = new Date(String(isoDateTime).replace(' ', 'T'));
    if (isNaN(then.getTime())) return '';
    const minutes = Math.max(0, Math.round((Date.now() - then.getTime()) / 60000));
    if (minutes < 1) return langData['dash_online_just_now'] || 'Just now';
    if (minutes < 60) return (langData['dash_online_minutes_ago'] || '{n}m ago').replace('{n}', minutes);
    const hours = Math.floor(minutes / 60);
    const remainingMinutes = minutes % 60;
    if (remainingMinutes === 0) {
        return (langData['dash_online_hours_ago_no_minutes'] || '{h}h ago').replace('{h}', hours);
    }
    return (langData['dash_online_hours_ago'] || '{h}h {m}m ago').replace('{h}', hours).replace('{m}', remainingMinutes);
}
function dashOnlineUserAvatarHtml(row) {
    const photo = row.profile_photo_thumbnail_path || row.profile_photo_path;
    const inner = photo
        ? `<img src="${BASE_URL}/${dashEscapeHtml(photo)}" alt="">`
        : `<span class="apv-person-avatar" style="width:30px;height:30px;min-width:30px;font-size:0.95rem;">${dashEscapeHtml((dashOnlineDisplayName(row) || '?').trim().charAt(0).toUpperCase() || '?')}</span>`;
    return `<div class="dash-online-avatar-wrap">${inner}<span class="dash-online-dot"></span></div>`;
}
function renderOnlineUsers(rows, total) {
    const $section = $('#dashOnlineUsersSection');
    const $list = $('#dashOnlineUsersList').empty();
    if (!rows || !rows.length) {
        $section.addClass('d-none');
        return;
    }
    $section.removeClass('d-none');
    $('#dashOnlineUsersCount').text((total || rows.length).toLocaleString());
    rows.forEach(function (row) {
        $list.append(`
            <div class="dash-online-user-row">
                ${dashOnlineUserAvatarHtml(row)}
                <span class="dash-online-user-name">${dashEscapeHtml(dashOnlineDisplayName(row))}</span>
                <span class="dash-online-user-since">${dashEscapeHtml(dashRelativeMinutesAgo(row.last_seen_at))}</span>
            </div>
        `);
    });
}

// See dashboard.php's own #dashProbationInternExpiringSection comment for the "why a card, why
// hidden when empty" context. Each row links straight to the employee's own profile (Employment or
// Salary tab is where an admin would actually go change employment_status/employment_type) -- this
// card itself never changes anything, purely informational per the user's own explicit instruction.
function renderProbationInternExpiring(rows) {
    const $section = $('#dashProbationInternExpiringSection');
    const $list = $('#dashProbationInternExpiringList').empty();
    if (!rows || !rows.length) {
        $section.addClass('d-none');
        return;
    }
    $section.removeClass('d-none');
    rows.forEach(function (row) {
        const url = `${BASE_URL}/employees/${encodeURIComponent(row.employee_no)}`;
        const name = currentLang === 'th' ? (row.name_th || row.name_en) : (row.name_en || row.name_th);
        const kindLabel = row.kind === 'internship' ? (langData['internship'] || 'Internship') : (langData['probation'] || 'Probation');
        const isExpired = row.milestone === 'expired';
        const statusHtml = isExpired
            ? `<span class="badge bg-danger-subtle text-danger-emphasis">${langData['dash_probation_intern_expired'] || 'Ended'} ${dashToDisplayDate(row.expiry_date)}</span>`
            : `<span class="badge bg-warning-subtle text-warning-emphasis">${(langData['dash_probation_intern_days_left'] || '{n} day(s) left').replace('{n}', row.days_remaining)}</span>`;
        $list.append(`
            <a href="${url}" target="_blank" rel="noopener" class="dash-run-row">
                <div class="dash-run-row-top">
                    <div class="dash-run-row-main">
                        <div class="dash-run-row-name">${dashEscapeHtml(name)} <span class="text-muted small">(${dashEscapeHtml(kindLabel)})</span></div>
                        <div class="dash-run-row-period">${langData['dash_probation_intern_expiry_date'] || 'Expiry date'}: ${dashToDisplayDate(row.expiry_date)}</div>
                    </div>
                    <div class="dash-run-row-meta">${statusHtml}</div>
                </div>
            </a>
        `);
    });
}

// 2026-09-02, explicit request: "อยากให้ดูเป็น Payroll มากขึ้น...ถ้าเพิ่มอะไรได้ก็อยากให้เพิ่ม" -- a plain
// day-count under the Upcoming Pay Date stat card, computed purely client-side from the same
// upcoming_run.payment_date the value above already renders (DashboardController::summary() already
// only ever returns a run whose payment_date is >= today, see that method's own `$upcoming` filter,
// so this is never negative -- no "overdue" case to handle).
function dashUpcomingPayCountdownText(paymentDateIso) {
    if (!paymentDateIso) return '';
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const payDate = new Date(paymentDateIso + 'T00:00:00');
    const days = Math.round((payDate - today) / 86400000);
    if (days <= 0) return langData['dash_pay_today'] || 'Pay day is today';
    return (langData['dash_days_until_pay'] || '{n} day(s) left').replace('{n}', days);
}

function renderPayrollWidgets(payroll, canViewAmounts) {
    $('#dashPendingApproval').text((payroll.pending_my_approval || 0).toLocaleString());
    const upcomingPaymentDate = payroll.upcoming_run && payroll.upcoming_run.payment_date;
    $('#dashUpcomingPayDate').text(upcomingPaymentDate ? dashToDisplayDate(upcomingPaymentDate) : '-');
    $('#dashUpcomingPayCountdown').text(dashUpcomingPayCountdownText(upcomingPaymentDate));

    const counts = payroll.counts || {};
    ['draft', 'pending_approval', 'approved', 'paid', 'locked'].forEach(function (state) {
        $(`#dashPipelineFlow .dash-pipeline-step[data-state="${state}"] .dash-pipeline-step-count`).text(counts[state] || 0);
    });

    renderCostTrendChart(canViewAmounts ? (payroll.cost_trend || []) : null);
    renderRecentRuns(payroll.recent_runs || [], canViewAmounts);
}

let dashCostTrendChartInstance = null;
// `rows` is `null` when the acting employee can't view payroll amounts (see
// DashboardController::summary()'s own comment -- the field is omitted entirely, not zeroed) --
// the whole card stays hidden in that case, same posture as Recent Runs' own amount column.
function renderCostTrendChart(rows) {
    const $section = $('#dashCostTrendSection');
    const $canvas = $('#dashCostTrendChart');
    if (!$canvas.length || typeof Chart === 'undefined') return;
    if (!rows || !rows.length) {
        $section.addClass('d-none');
        $('#dashCostTrendTotal').text('');
        if (dashCostTrendChartInstance) { dashCostTrendChartInstance.destroy(); dashCostTrendChartInstance = null; }
        return;
    }
    $section.removeClass('d-none');
    const totalAmount = rows.reduce((sum, r) => sum + (Number(r.net_amount) || 0), 0);
    const totalTpl = langData['dash_cost_trend_total'] || 'Total (last {n} months): {amount}';
    $('#dashCostTrendTotal').text(totalTpl.replace('{n}', rows.length).replace('{amount}', dashFmtNum(totalAmount)));
    const labels = rows.map(r => {
        const parts = String(r.month).split('-');
        return parts.length === 2 ? `${parts[1]}/${parts[0]}` : r.month;
    });
    const data = rows.map(r => Number(r.net_amount) || 0);
    if (dashCostTrendChartInstance) {
        dashCostTrendChartInstance.data.labels = labels;
        dashCostTrendChartInstance.data.datasets[0].data = data;
        dashCostTrendChartInstance.update();
        return;
    }
    dashCostTrendChartInstance = new Chart($canvas[0].getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: langData['dash_cost_trend'] || 'Payroll Cost Trend',
                data: data,
                backgroundColor: '#FF9900',
                borderRadius: 4,
                maxBarThickness: 48,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { callback: v => Number(v).toLocaleString() } },
            },
        },
    });
}

function renderRecentRuns(rows, canViewAmounts) {
    const $list = $('#dashRecentRunsList').empty();
    if (!rows.length) {
        $list.append(`<div class="text-muted small text-center py-4">${langData['dash_no_recent_runs'] || 'No payroll runs yet.'}</div>`);
        return;
    }
    rows.forEach(function (row) {
        const url = `${BASE_URL}/payroll-process/${row.public_id}`;
        const period = `${dashToDisplayDate(row.period_start_date)} - ${dashToDisplayDate(row.period_end_date)}`;
        const amountHtml = canViewAmounts ? `<div class="dash-run-row-amount">${dashFmtNum(row.total_net_amount)}</div>` : '';
        $list.append(`
            <a href="${url}" target="_blank" rel="noopener" class="dash-run-row">
                <div class="dash-run-row-top">
                    <div class="dash-run-row-main">
                        <div class="dash-run-row-name">${dashEscapeHtml(row.run_name)}</div>
                        <div class="dash-run-row-period">${period}</div>
                    </div>
                    <div class="dash-run-row-meta">${amountHtml}</div>
                </div>
                ${dashRunTimelineHtml(row)}
            </a>
        `);
    });
}

// ==================== Month/Year period picker (2026-09-07 redesign) ====================
// Replaces the old 2-plain-<select> bar (month + year, both select2) with a single button that
// opens a small calendar-style popover: year navigation on top, a 12-month grid below -- reads as
// "pick a period" the way a real calendar control does, instead of two bare dropdowns. Also removes
// the only `.select2-native` element this page had (see loadDashboardSummary()'s own docblock for
// why that was the actual root cause of the "loads twice" bug this same redesign fixes).
// `dashSelectedYear`/`dashSelectedMonth` are the CURRENTLY LOADED period (kept in sync from
// renderDashboard(), the authoritative source); `dashPickerViewYear` is only which year the popover
// is currently showing (lets you browse other years without changing what's loaded until you
// actually click a month).
let dashSelectedYear = null;
let dashSelectedMonth = null;
let dashPickerViewYear = new Date().getFullYear();

function dashUpdatePeriodPickerLabel() {
    if (!dashSelectedYear || !dashSelectedMonth) return;
    const monthLabel = langData['month_' + dashSelectedMonth] || dashSelectedMonth;
    $('#dashPeriodPickerLabel').text(`${monthLabel} ${dashSelectedYear}`);
}
function dashRenderPeriodPickerMonths() {
    $('#dashPeriodPickerYearLabel').text(dashPickerViewYear);
    const $grid = $('#dashPeriodPickerMonths').empty();
    for (let m = 1; m <= 12; m++) {
        const isSelected = dashSelectedYear === dashPickerViewYear && dashSelectedMonth === m;
        const label = langData['month_' + m] || m;
        $grid.append(`<button type="button" class="dash-period-picker-month-btn${isSelected ? ' active' : ''}" data-month="${m}">${dashEscapeHtml(label)}</button>`);
    }
}
$(document).on('click', '#dashPeriodPickerBtn', function (e) {
    e.stopPropagation();
    const $pop = $('#dashPeriodPickerPop');
    if (!$pop.hasClass('d-none')) {
        $pop.addClass('d-none');
        return;
    }
    dashPickerViewYear = dashSelectedYear || new Date().getFullYear();
    dashRenderPeriodPickerMonths();
    $pop.removeClass('d-none');
});
$(document).on('click', '#dashPeriodPickerPop', function (e) { e.stopPropagation(); });
$(document).on('click', function () {
    $('#dashPeriodPickerPop').addClass('d-none');
});
$(document).on('click', '#dashPeriodYearPrevBtn', function () {
    dashPickerViewYear--;
    dashRenderPeriodPickerMonths();
});
$(document).on('click', '#dashPeriodYearNextBtn', function () {
    dashPickerViewYear++;
    dashRenderPeriodPickerMonths();
});
$(document).on('click', '.dash-period-picker-month-btn', function () {
    const month = Number($(this).data('month'));
    $('#dashPeriodPickerPop').addClass('d-none');
    if (month === dashSelectedMonth && dashPickerViewYear === dashSelectedYear) return;
    loadDashboardSummary(dashPickerViewYear, month);
});
$(document).on('click', '#dashPeriodBackToCurrentBtn', function () {
    $('#dashPeriodPickerPop').addClass('d-none');
    loadDashboardSummary();
});
$(document).on('click', '#dashCalendarTodayBtn', function () {
    $('#dashPeriodPickerPop').addClass('d-none');
    loadDashboardSummary();
});

// 2026-09-07, explicit request: "กด < > ไปดูได้" -- one-month-at-a-time step buttons flanking the
// picker inside the Calendar card's own nav row (see dashboard.php's own .dash-calendar-nav). Same
// loadDashboardSummary(year, month) entry point the popover's own month grid already uses -- this
// is just a quicker way to reach an ADJACENT month without opening it.
function dashStepPeriod(delta) {
    if (!dashSelectedYear || !dashSelectedMonth) return;
    let year = dashSelectedYear;
    let month = dashSelectedMonth + delta;
    if (month < 1) { month = 12; year -= 1; }
    else if (month > 12) { month = 1; year += 1; }
    loadDashboardSummary(year, month);
}
$(document).on('click', '#dashPeriodPrevBtn', function () { dashStepPeriod(-1); });
$(document).on('click', '#dashPeriodNextBtn', function () { dashStepPeriod(1); });

// ==================== Calendar widget (2026-09-06) ====================
// Confirmed via AskUserQuestion: holidays + payroll cutoff/payment dates + probation/internship end
// dates, combined -- see DashboardModel::calendarEvents()'s own docblock. Driven by the SAME
// month/year picker as the rest of the page (single source of truth) rather than its own
// independent prev/next navigation, so the Calendar can never show a different month than every
// other widget on the page.
const DASH_CAL_TYPE_CLASS = {
    holiday: 'dash-cal-dot-holiday',
    payroll_cutoff: 'dash-cal-dot-cutoff',
    payroll_payment: 'dash-cal-dot-payment',
    probation_end: 'dash-cal-dot-probation',
    internship_end: 'dash-cal-dot-probation',
};
const DASH_CAL_TYPE_LABEL_KEYS = {
    holiday: 'dash_cal_holiday',
    payroll_cutoff: 'dash_cal_cutoff',
    payroll_payment: 'dash_cal_payment',
    probation_end: 'dash_cal_probation',
    internship_end: 'dash_cal_probation',
};
let dashCalendarEventsByDate = {};
function loadDashboardCalendar(year, month) {
    if (!year || !month) return;
    $.ajax({
        url: `${BASE_URL}/api/dashboard.calendar`, method: 'GET', data: { year: year, month: month }, dataType: 'json',
        success: function (res) {
            if (!res.status) return;
            dashCalendarEventsByDate = {};
            (res.data.events || []).forEach(function (ev) {
                (dashCalendarEventsByDate[ev.date] = dashCalendarEventsByDate[ev.date] || []).push(ev);
            });
            $('#dashCalendarDayDetail').addClass('d-none').empty();
            renderDashboardCalendarGrid(Number(year), Number(month));
        }
    });
}
function renderDashboardCalendarGrid(year, month) {
    const $grid = $('#dashCalendarGrid').empty();
    const weekdayKeys = ['weekday_short_sun', 'weekday_short_mon', 'weekday_short_tue', 'weekday_short_wed', 'weekday_short_thu', 'weekday_short_fri', 'weekday_short_sat'];
    const weekdayFallbacks = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
    let headerHtml = '<div class="dash-calendar-row dash-calendar-header-row">';
    weekdayKeys.forEach((k, i) => headerHtml += `<div class="dash-calendar-cell dash-calendar-weekday">${dashEscapeHtml(langData[k] || weekdayFallbacks[i])}</div>`);
    headerHtml += '</div>';

    const daysInMonth = new Date(year, month, 0).getDate();
    const startWeekday = new Date(year, month - 1, 1).getDay(); // 0=Sun
    const todayStr = new Date().toISOString().slice(0, 10);

    const cells = [];
    for (let i = 0; i < startWeekday; i++) cells.push(null);
    for (let d = 1; d <= daysInMonth; d++) cells.push(d);
    while (cells.length % 7 !== 0) cells.push(null);

    let bodyHtml = '';
    for (let i = 0; i < cells.length; i++) {
        if (i % 7 === 0) bodyHtml += '<div class="dash-calendar-row">';
        const d = cells[i];
        if (d === null) {
            bodyHtml += '<div class="dash-calendar-cell dash-calendar-cell-empty"></div>';
        } else {
            const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const events = dashCalendarEventsByDate[dateStr] || [];
            const distinctTypes = [...new Set(events.map(e => e.type))];
            const dotsHtml = distinctTypes.length
                ? `<div class="dash-cal-dots">${distinctTypes.map(t => `<span class="dash-cal-dot ${DASH_CAL_TYPE_CLASS[t] || ''}"></span>`).join('')}</div>`
                : '';
            const isToday = dateStr === todayStr;
            bodyHtml += `<div class="dash-calendar-cell dash-calendar-day${isToday ? ' dash-calendar-today' : ''}${events.length ? ' dash-calendar-has-events' : ''}" data-date="${dateStr}">
                <span class="dash-calendar-day-num">${d}</span>${dotsHtml}
            </div>`;
        }
        if (i % 7 === 6) bodyHtml += '</div>';
    }
    $grid.html(headerHtml + bodyHtml);
}
$(document).on('click', '.dash-calendar-day.dash-calendar-has-events', function () {
    const date = $(this).data('date');
    const events = dashCalendarEventsByDate[date] || [];
    const $detail = $('#dashCalendarDayDetail');
    $('.dash-calendar-day').removeClass('dash-calendar-day-selected');
    if (!events.length) {
        $detail.addClass('d-none').empty();
        return;
    }
    $(this).addClass('dash-calendar-day-selected');
    const labelField = currentLang === 'th' ? 'label_th' : 'label_en';
    const rowsHtml = events.map(e => `<div class="small"><span class="dash-cal-dot ${DASH_CAL_TYPE_CLASS[e.type] || ''}"></span> ${dashEscapeHtml(langData[DASH_CAL_TYPE_LABEL_KEYS[e.type]] || e.type)}: ${dashEscapeHtml(e[labelField] || e.label_en || '')}</div>`).join('');
    $detail.removeClass('d-none').html(`<div class="fw-semibold small mb-1">${dashToDisplayDate(date)}</div>${rowsHtml}`);
});

// ==================== Headcount by Department chart (2026-09-06) ====================
// One additional, deliberately restrained chart -- per explicit request "เพิ่มกราฟที่สามารถเพิ่มได้ แต่
// ไม่ดูยัดเยียดเกินไป" -- a compact horizontal bar in the sidebar column, capped at the top 6
// departments so a company with many departments doesn't get a chart taller than the page itself.
let dashDeptChartInstance = null;
function renderDepartmentChart(rows) {
    const $section = $('#dashDeptChartSection');
    const $canvas = $('#dashDeptChart');
    if (!$canvas.length || typeof Chart === 'undefined') return;
    const filtered = (rows || []).filter(r => Number(r.count) > 0);
    if (!filtered.length) {
        $section.addClass('d-none');
        if (dashDeptChartInstance) { dashDeptChartInstance.destroy(); dashDeptChartInstance = null; }
        return;
    }
    $section.removeClass('d-none');
    const top = filtered.slice(0, 6);
    const labels = top.map(r => currentLang === 'th' ? r.department_name_th : r.department_name_en);
    const data = top.map(r => Number(r.count) || 0);
    if (dashDeptChartInstance) {
        dashDeptChartInstance.data.labels = labels;
        dashDeptChartInstance.data.datasets[0].data = data;
        dashDeptChartInstance.update();
        return;
    }
    dashDeptChartInstance = new Chart($canvas[0].getContext('2d'), {
        type: 'bar',
        data: { labels: labels, datasets: [{ data: data, backgroundColor: '#FF9900', borderRadius: 4, maxBarThickness: 22 }] },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
        },
    });
}

$(document).ready(function () {
    loadDashboardSummary();
    dashCheckPendingAnnouncements();
});
