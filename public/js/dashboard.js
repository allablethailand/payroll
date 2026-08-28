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

// Same state -> badge-class map as public/js/payroll/index.js's own stateBadgePr() -- duplicated
// rather than shared, matching this app's existing per-page self-contained JS convention.
function dashStateBadge(state) {
    const map = {
        draft: 'bg-secondary-subtle text-secondary',
        pending_approval: 'bg-warning-subtle text-warning',
        approved: 'bg-info-subtle text-info',
        paid: 'bg-success-subtle text-success',
        locked: 'bg-dark-subtle text-dark',
        rejected: 'bg-danger-subtle text-danger',
        cancelled: 'bg-dark-subtle text-muted',
        need_info: 'bg-primary-subtle text-primary',
    };
    const cls = map[state] || 'bg-light text-dark';
    const text = langData['state_' + state] || state;
    return `<span class="badge ${cls}">${text}</span>`;
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

    if (data.can_view_payroll && data.payroll) {
        renderPayrollWidgets(data.payroll);
    } else {
        $('#dashStatPendingApprovalCol, #dashStatUpcomingPayCol, #dashPipelineSection, #dashRecentRunsSection').addClass('d-none');
    }
}

function renderPayrollWidgets(payroll) {
    $('#dashStatPendingApprovalCol, #dashStatUpcomingPayCol, #dashPipelineSection, #dashRecentRunsSection').removeClass('d-none');
    $('#dashPendingApproval').text((payroll.pending_my_approval || 0).toLocaleString());
    $('#dashUpcomingPayDate').text(payroll.upcoming_run && payroll.upcoming_run.payment_date
        ? dashToDisplayDate(payroll.upcoming_run.payment_date)
        : '-');

    const counts = payroll.counts || {};
    ['draft', 'pending_approval', 'approved', 'paid', 'locked'].forEach(function (state) {
        $(`#dashStationRow .station-card[data-state="${state}"] .station-count`).text(counts[state] || 0);
    });

    renderRecentRuns(payroll.recent_runs || []);
}

function renderRecentRuns(rows) {
    const $list = $('#dashRecentRunsList').empty();
    if (!rows.length) {
        $list.append(`<div class="text-muted small text-center py-4">${langData['dash_no_recent_runs'] || 'No payroll runs yet.'}</div>`);
        return;
    }
    rows.forEach(function (row) {
        const url = `${BASE_URL}/payroll-process/${row.public_id}`;
        const period = `${dashToDisplayDate(row.period_start_date)} - ${dashToDisplayDate(row.period_end_date)}`;
        $list.append(`
            <a href="${url}" class="dash-run-row">
                <div class="dash-run-row-main">
                    <div class="dash-run-row-name">${dashEscapeHtml(row.run_name)}</div>
                    <div class="dash-run-row-period">${period}</div>
                </div>
                <div class="dash-run-row-meta">
                    <div class="dash-run-row-amount">${dashFmtNum(row.total_net_amount)}</div>
                    ${dashStateBadge(row.state)}
                </div>
            </a>
        `);
    });
}

$(document).ready(function () {
    loadDashboardSummary();
});
