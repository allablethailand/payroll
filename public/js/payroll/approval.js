let tb_payroll_approval;

function toDisplayDateAp(isoVal) {
    if (!isoVal) return '';
    const parts = String(isoVal).split('-');
    if (parts.length !== 3) return isoVal;
    const [yyyy, mm, dd] = parts;
    return `${dd}/${mm}/${yyyy}`;
}
function escapeHtmlAp(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}
function fmtNumAp(n) {
    return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function submitterNameAp(row) {
    return (currentLang === 'th' ? row.submitted_by_name_th : row.submitted_by_name_en) || row.submitted_by_name_th || row.submitted_by_name_en || '-';
}

function initPayrollApprovalTable() {
    if ($.fn.DataTable.isDataTable('#tb_payroll_approval')) {
        $('#tb_payroll_approval').DataTable().ajax.reload(null, false);
        return;
    }
    tb_payroll_approval = $('#tb_payroll_approval').DataTable({
        responsive: true,
        order: [[5, 'asc']],
        ajax: {
            url: `${BASE_URL}/api/payroll-run.list`,
            dataSrc: function (res) {
                const data = res.data || [];
                $('#noPendingApproval').toggleClass('d-none', data.length > 0);
                $('#tb_payroll_approval').toggleClass('d-none', data.length === 0);
                return data;
            },
            data: function (d) {
                d.state = 'pending_approval';
            }
        },
        columns: [
            { data: 'run_name', render: d => `<strong class="text-dark">${escapeHtmlAp(d)}</strong>` },
            { data: null, render: (d, t, row) => `${toDisplayDateAp(row.period_start_date)} - ${toDisplayDateAp(row.period_end_date)}` },
            { data: 'employee_count', className: 'text-end' },
            { data: 'total_net_amount', className: 'text-end', render: d => fmtNumAp(d) },
            { data: null, render: (d, t, row) => escapeHtmlAp(submitterNameAp(row)) },
            { data: 'submitted_at', render: d => d ? toDisplayDateAp(d.substring(0, 10)) + ' ' + d.substring(11, 16) : '-' },
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        drawCallback: function () { getTableLang(); }
    });
    $('#tb_payroll_approval tbody').off('click', 'tr').on('click', 'tr', function () {
        const rowData = tb_payroll_approval.row(this).data();
        if (rowData && rowData.public_id) {
            window.location.href = `${BASE_URL}/payroll-process/${rowData.public_id}`;
        }
    });
}

$(document).ready(function () {
    initPayrollApprovalTable();
});
