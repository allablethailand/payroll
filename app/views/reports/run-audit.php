<div class="container container-body">
    <?php
    $breadcrumb = [
        ['label' => 'Reports', 'href' => BASE_URL . '/reports', 'i18n' => 'reports'],
        ['label' => 'Payroll Run Audit', 'href' => null, 'i18n' => 'payroll_run_audit_menu'],
    ];
    $description = 'Which payroll runs were manually edited, by whom, and what changed -- drill into any run for a before/after breakdown.';
    $description_i18n = 'run_audit_page_description';
    include __DIR__ . '/../partials/page-header.php';
    ?>

    <div class="alert alert-info small mb-4" id="runAuditHistoryStartNotice">
        <i class="fa-solid fa-circle-info me-1"></i>
        <span data-i18n="run_audit_history_start_notice">Edit history is only recorded starting 2026-08-31. Runs created before that date may show "no edit history available" even if they were edited earlier -- that data was never captured in a structured form.</span>
    </div>

    <!-- 2026-08-31, same-day follow-up, explicit request: "อยากให้เพิ่ม Filter ด้วยครับ และมี pipeline
         Status ให้ด้วยครับ และให้มี Status All ด้วยครับ" -- Date From/To is a real server-side filter
         (PayrollRunModel::runAuditList()'s own date_from/date_to, same convention as every other
         List page's own Date filter box); the pipeline bar below it is a client-side state filter
         over whatever that server-side filter already returned (same "load once, filter via
         DataTables ext.search" pattern the Payroll Process List page's own station cards already
         use) -- state isn't filtered server-side since the pipeline bar needs live per-state counts
         across the WHOLE filtered set anyway, which a state-only-server-filter would need a second
         query for. -->
    <?php
    ob_start(); ?>
    <div class="row g-2">
        <div class="col-sm-4 col-md-3">
            <label class="form-label small mb-1" for="runAuditFilterDateFrom" data-i18n="filter_date_from">From</label>
            <input type="text" class="form-control datepicker" id="runAuditFilterDateFrom" autocomplete="off">
        </div>
        <div class="col-sm-4 col-md-3">
            <label class="form-label small mb-1" for="runAuditFilterDateTo" data-i18n="filter_date_to">To</label>
            <input type="text" class="form-control datepicker" id="runAuditFilterDateTo" autocomplete="off">
        </div>
    </div>
    <?php
    $filter_fields_html = ob_get_clean();
    $id = 'runAuditFilterBar';
    include __DIR__ . '/../partials/filter-bar.php';
    ?>

    <div class="station-row mb-3" id="runAuditStationRow">
        <div class="station-col">
            <div class="station-card active" data-state="all">
                <span data-i18n="status_all">All</span> <span class="station-count">0</span>
            </div>
        </div>
        <div class="station-col">
            <div class="station-card" data-state="draft">
                <span data-i18n="state_draft">In Progress</span> <span class="station-count">0</span>
            </div>
        </div>
        <div class="station-col">
            <div class="station-card" data-state="pending_approval">
                <span data-i18n="state_pending_approval">Pending Approval</span> <span class="station-count">0</span>
            </div>
        </div>
        <div class="station-col">
            <div class="station-card" data-state="approved">
                <span data-i18n="state_approved">Approved</span> <span class="station-count">0</span>
            </div>
        </div>
        <div class="station-col">
            <div class="station-card" data-state="paid">
                <span data-i18n="state_paid">Paid</span> <span class="station-count">0</span>
            </div>
        </div>
        <div class="station-col">
            <div class="station-card" data-state="locked">
                <span data-i18n="state_locked">Locked</span> <span class="station-count">0</span>
            </div>
        </div>
        <div class="station-col station-col--reject">
            <div class="station-card station-card--reject" data-state="rejected">
                <div class="station-card-inner">
                    <span data-i18n="state_rejected">Rejected (Sent Back)</span> <span class="station-count">0</span>
                </div>
            </div>
        </div>
        <div class="station-col station-col--reject">
            <div class="station-card station-card--reject station-card--needinfo" data-state="need_info">
                <div class="station-card-inner">
                    <span data-i18n="state_need_info">Need Information</span> <span class="station-count">0</span>
                </div>
            </div>
        </div>
        <div class="station-col station-col--reject">
            <div class="station-card station-card--reject station-card--cancel" data-state="cancelled">
                <div class="station-card-inner">
                    <span data-i18n="state_cancelled">Cancelled</span> <span class="station-count">0</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card-surface p-3 mb-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="tb_run_audit_list" style="width:100%;">
                <thead>
                    <tr>
                        <th><span data-i18n="run_name">Run Name</span></th>
                        <th><span data-i18n="cycle">Cycle</span></th>
                        <th><span data-i18n="origin">Origin</span></th>
                        <th><span data-i18n="table_status">Status</span></th>
                        <th><span data-i18n="pay_period">Pay Period</span></th>
                        <th class="num"><span data-i18n="run_audit_edit_count">Edits</span></th>
                        <th><span data-i18n="table_action">Action</span></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Drill-down diff modal -->
<div class="modal fade" id="runAuditDiffModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-secondary" data-i18n="run_audit_diff_title">Payroll Run Audit - Diff</h5>
                <button type="button" class="btn btn-outline-secondary btn-sm me-2" id="runAuditExportBtn">
                    <i class="fa-solid fa-file-excel me-1"></i><span data-i18n="export_excel">Export Excel</span>
                </button>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning small d-none" id="runAuditNoHistoryNotice">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>
                    <span data-i18n="run_audit_no_history_available">No edit history available for this run (feature started 2026-08-31) -- this doesn't necessarily mean the run was never edited, just that no structured before/after record exists for edits made before that date.</span>
                </div>
                <div class="alert alert-secondary small d-none" id="runAuditEmptyNotice">
                    <i class="fa-solid fa-check-circle me-1"></i>
                    <span data-i18n="run_audit_no_edits">No manual edits recorded for this run -- every figure shown is the original computed value.</span>
                </div>
                <div class="table-responsive" id="runAuditDiffTableWrap" style="max-height:75vh;"></div>
            </div>
        </div>
    </div>
</div>

<script src="<?=asset('public/js/reports/run-audit.js')?>"></script>
