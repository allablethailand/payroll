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

function loadDashboardSummary() {
    $.ajax({
        url: `${BASE_URL}/api/dashboard.summary`,
        method: 'GET',
        dataType: 'json',
        success: function (res) {
            if (!res || !res.status) return;
            renderDashboard(res.data || {});
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
}

function renderPayrollWidgets(payroll, canViewAmounts) {
    $('#dashPendingApproval').text((payroll.pending_my_approval || 0).toLocaleString());
    $('#dashUpcomingPayDate').text(payroll.upcoming_run && payroll.upcoming_run.payment_date
        ? dashToDisplayDate(payroll.upcoming_run.payment_date)
        : '-');

    const counts = payroll.counts || {};
    ['draft', 'pending_approval', 'approved', 'paid', 'locked'].forEach(function (state) {
        $(`#dashStationRow .station-card[data-state="${state}"] .station-count`).text(counts[state] || 0);
    });

    renderRecentRuns(payroll.recent_runs || [], canViewAmounts);
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

// 2026-08-29, explicit request: notification summary card, see this file's own dashNotifSection
// comment in dashboard.php. notifItemHtml()/BASE_URL are defined in notifications.js, loaded
// globally on every page (layout/header.php) before this file's own <script> tag at the bottom of
// dashboard.php, so both are already available here with no extra require.
function loadDashboardNotifications() {
    $.getJSON(`${BASE_URL}/api/notification.list`, { offset: 0, limit: 5 }, function (res) {
        if (!res.status) return;
        const rows = res.data || [];
        $('#dashNotifEmpty').toggleClass('d-none', rows.length > 0);
        $('#dashNotifList').find('.nav-notif-item').remove();
        rows.forEach(item => $('#dashNotifList').append(typeof notifItemHtml === 'function' ? notifItemHtml(item) : ''));
    });
}
$(document).ready(function () {
    loadDashboardSummary();
    loadDashboardNotifications();
});
