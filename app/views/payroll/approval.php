<style>
#tb_payroll_approval tbody tr { cursor: pointer; }
</style>
<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> Payroll</span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="payroll_approval">Payroll Approval</span>
        </h5>
    </nav>
    <div class="mb-4">
        <h5 class="text-secondary fw-bold m-0">
            <i class="fa-solid fa-clipboard-check me-2"></i>
            <span data-i18n="payroll_approval">Payroll Approval</span>
        </h5>
        <p class="text-muted small m-0 mt-1" data-i18n="payroll_approval_description">Payroll runs waiting for your approval. Click a row to review and approve, reject, or send it back for revision.</p>
    </div>

    <div class="card-surface p-3 p-md-4">
        <div id="noPendingApproval" class="text-center text-secondary py-5 d-none">
            <i class="fa-solid fa-circle-check fa-2x mb-3 d-block text-secondary opacity-50"></i>
            <span data-i18n="no_pending_approval">No payroll runs are waiting for approval right now.</span>
        </div>
        <table class="table table-hover table-border align-middle w-100" id="tb_payroll_approval">
            <thead class="table-light text-secondary">
                <tr>
                    <th scope="col" style="width: 22%;" data-i18n="table_run_name">Run Name</th>
                    <th scope="col" style="width: 15%;" data-i18n="table_period">Pay Period</th>
                    <th scope="col" style="width: 10%;" data-i18n="table_employee_count">Employees</th>
                    <th scope="col" style="width: 13%;" data-i18n="table_net_amount">Net Total</th>
                    <th scope="col" style="width: 15%;" data-i18n="table_submitted_by">Submitted By</th>
                    <th scope="col" style="width: 15%;" data-i18n="table_submitted_at">Submitted At</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
<script src="<?=asset('public/js/payroll/approval.js')?>"></script>
