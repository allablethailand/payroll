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

// 2026-08-29, explicit request: "รายการเงินเดือนล่าสุด ใส่ timeline ให้เห็นว่าปัจจุบันถึงไหนแล้ว ขอ Design
// เดียวกับหน้าทำเงินเดือน" -- replaces the old plain state badge (dashStateBadge(), removed) with a
// mini version of the SAME chevron pipeline component Payroll Process's own station-row uses
// (.station-card/.station-card--reject etc., see style.css), scaled down (.station-card-sm) so it
// fits inline per-row in a compact list instead of the full-size interactive filter bar. Draft ->
// Pending Approval -> Approved -> Paid -> Locked is the canonical forward order; a run currently
// sitting in one of those 5 shows every earlier stage as "done" (soft green) and that one stage
// highlighted in its own color, with the stages still ahead left dim/neutral -- literally "how far
// along it currently is". A run that got rejected/cancelled/need-info branched OFF the forward flow
// at Pending Approval, so those states render as one extra highlighted segment appended right after
// Pending Approval instead of continuing along Approved/Paid/Locked, which are left visibly
// "skipped" (very light, not the same low-key grey as a not-yet-reached step, since -- unlike an
// upcoming step -- they were never going to happen for this run).
const DASH_RUN_STAGES = ['draft', 'pending_approval', 'approved', 'paid', 'locked'];
const DASH_RUN_BRANCH_CLASS = { rejected: 'station-card-sm--danger', cancelled: 'station-card-sm--muted', need_info: 'station-card-sm--info' };
function dashRunTimelineHtml(state) {
    const branchClass = DASH_RUN_BRANCH_CLASS[state];
    const currentIdx = branchClass ? 1 : DASH_RUN_STAGES.indexOf(state);
    let html = '<div class="station-row-sm">';
    DASH_RUN_STAGES.forEach(function (stage, idx) {
        let cls = 'station-card-sm';
        if (!branchClass && idx === currentIdx) cls += ' active';
        else if (idx < currentIdx || (branchClass && idx <= 1)) cls += ' station-card-sm--done';
        else if (branchClass) cls += ' station-card-sm--skipped';
        html += `<div class="${cls}" data-state="${dashEscapeHtml(stage)}">${dashEscapeHtml(langData['state_' + stage] || stage)}</div>`;
        if (branchClass && idx === 1) {
            html += `<div class="station-card-sm active ${branchClass}">${dashEscapeHtml(langData['state_' + state] || state)}</div>`;
        }
    });
    html += '</div>';
    return html;
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
            <a href="${url}" class="dash-run-row">
                <div class="dash-run-row-top">
                    <div class="dash-run-row-main">
                        <div class="dash-run-row-name">${dashEscapeHtml(row.run_name)}</div>
                        <div class="dash-run-row-period">${period}</div>
                    </div>
                    <div class="dash-run-row-meta">${amountHtml}</div>
                </div>
                ${dashRunTimelineHtml(row.state)}
            </a>
        `);
    });
}

$(document).ready(function () {
    loadDashboardSummary();
});
