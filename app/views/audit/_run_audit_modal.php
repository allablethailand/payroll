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
