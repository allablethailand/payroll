<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i><span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <a href="<?=BASE_URL?>/reports" class="bc-parent text-decoration-none" data-i18n="reports">Reports</a>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="payroll_run_audit_menu">Payroll Run Audit</span>
        </h5>
    </nav>
    <!-- 2026-08-31, same-day follow-up (item 10, explicit request: "Design ให้หน่อยครับ No Idea") --
         list every payroll run with its origin + how many manual edits it has on record, drill into
         one run for a per-employee/per-item Original -> Edit 1 -> Edit 2 -> ... -> Current diff
         table. See PayrollRunModel::runAuditList()/lineOverrideAuditDiff()'s own docblocks for the
         data design, and payroll_run_line_override_history's migration comment for why runs from
         before this feature shipped can genuinely show "no edit history available" even if they
         WERE manually edited. -->
    <div class="page-header-card mb-4">
        <div class="page-header-card-icon"><i class="fa-solid fa-magnifying-glass-chart"></i></div>
        <div class="page-header-card-body">
            <h5 class="page-header-card-title" data-i18n="payroll_run_audit_menu">Payroll Run Audit</h5>
            <p class="page-header-card-desc" data-i18n="run_audit_page_description">Which payroll runs were manually edited, by whom, and what changed -- drill into any run for a before/after breakdown.</p>
        </div>
    </div>

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
    <div class="station-filter" id="runAuditStationFilter">
        <span class="station-filter-label" data-i18n="label_filter">Filter</span>
        <button type="button" class="station-filter-toggle" id="runAuditStationFilterToggle" title="Toggle filter">
            <i class="fas fa-chevron-up"></i>
        </button>
        <div class="station-filter-body">
            <div class="row g-2">
                <div class="col-sm-4 col-md-3">
                    <label class="form-label mb-1"><i class="fa-solid fa-calendar-days me-1 text-muted"></i><span data-i18n="filter_date_from">From</span></label>
                    <div class="input-group">
                        <input type="text" class="form-control datepicker" id="runAuditFilterDateFrom" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
                <div class="col-sm-4 col-md-3">
                    <label class="form-label mb-1"><i class="fa-solid fa-calendar-days me-1 text-muted"></i><span data-i18n="filter_date_to">To</span></label>
                    <div class="input-group">
                        <input type="text" class="form-control datepicker" id="runAuditFilterDateTo" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="station-filter-clear-row d-none" id="runAuditFilterClearRow">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="runAuditClearDateFilter">
            <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
        </button>
    </div>

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
                        <th data-i18n="run_name">Run Name</th>
                        <th data-i18n="cycle">Cycle</th>
                        <th data-i18n="origin">Origin</th>
                        <th data-i18n="table_status">Status</th>
                        <th data-i18n="pay_period">Pay Period</th>
                        <th data-i18n="run_audit_edit_count">Edits</th>
                        <th data-i18n="table_action">Action</th>
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

<script src="<?=BASE_URL?>/public/js/reports/run-audit.js"></script>
