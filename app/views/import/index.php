<?php
// design:clean -- docs/design/rules.md §12. Passes scripts/check-design.php with 0 hits.
?>
<div class="container container-body">
    <?php
    $breadcrumb = [
        ['label' => 'Settings', 'href' => null, 'i18n' => 'settings'],
        ['label' => 'Data Import', 'href' => null, 'i18n' => 'data_import_menu'],
    ];
    $description = 'Upload a file, match its columns, fix any errors, then import.';
    include __DIR__ . '/../partials/page-header.php';
    ?>

    <ul class="nav nav-tabs flex-nowrap scrollable-tabs setup-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="imp-upload-tab" data-bs-toggle="tab" data-bs-target="#imp-upload-pane" type="button" role="tab" aria-controls="imp-upload-pane" aria-selected="true">
                <span data-i18n="import_tab_upload">Import</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="imp-log-tab" data-bs-toggle="tab" data-bs-target="#imp-log-pane" type="button" role="tab" aria-controls="imp-log-pane" aria-selected="false">
                <span data-i18n="import_tab_log">Activity Log</span>
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="imp-upload-pane" role="tabpanel" aria-labelledby="imp-upload-tab" tabindex="0">
            <form id="impUploadForm" class="row g-3 mt-0" novalidate>
                <div class="col-md-4">
                    <label class="form-label" for="impEntityType" data-i18n="import_entity_type">Data type</label>
                    <select class="form-select" id="impEntityType" data-option-keys="<?=htmlspecialchars(implode(',', array_map(fn($t) => ['employee_import' => 'import_entity_employees', 'ytd_opening' => 'import_entity_ytd', 'adhoc_item' => 'import_entity_adhoc', 'attendance_summary' => 'import_entity_attendance_summary'][$t] ?? $t, $entityTypes)))?>" data-option-values="<?=htmlspecialchars(implode(',', $entityTypes))?>"></select>
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="impFile" data-i18n="import_file">File (.csv, .xlsx)</label>
                    <input type="file" class="form-control" id="impFile" accept=".csv,.xlsx,.xls">
                </div>
                <div class="col-12 d-flex gap-2 justify-content-end">
                    <button type="button" class="btn btn-outline-secondary" id="impDownloadTemplate" data-i18n="import_download_template">Download Template</button>
                    <button type="submit" class="btn btn-primary" id="impUploadBtn" data-i18n="import_upload">Upload</button>
                </div>
            </form>
        </div>

        <div class="tab-pane fade" id="imp-log-pane" role="tabpanel" aria-labelledby="imp-log-tab" tabindex="0">
            <div class="table-responsive">
            <table class="table table-hover w-100" id="tb_import_log">
                <thead>
                    <tr>
                        <th><span data-i18n="import_log_time">When</span></th>
                        <th><span data-i18n="import_log_user">By</span></th>
                        <th><span data-i18n="import_entity_type">Data type</span></th>
                        <th><span data-i18n="import_log_action">Action</span></th>
                        <th><span data-i18n="import_log_result">Result</span></th>
                        <th><span data-i18n="import_log_file">File</span></th>
                        <th class="text-end"><span data-i18n="import_log_rows">Rows</span></th>
                        <th class="text-end"><span data-i18n="import_log_failed">Failed</span></th>
                        <th><span data-i18n="import_log_ip">IP</span></th>
                        <th><span data-i18n="import_log_device">Device</span></th>
                        <th><span data-i18n="import_log_actions">Actions</span></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="impWizardModal" tabindex="-1" aria-labelledby="impWizardTitle" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="impWizardTitle" data-i18n="import_modal_title">Import Data</h5>
                <button type="button" class="btn-close" id="impWizardClose" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="impStepMap">
                    <p class="text-muted mb-3" data-i18n="import_map_hint">Match each column in your file to a field. Columns left as "Do not import" are ignored.</p>
                    <table class="table align-middle" id="tb_import_mapping">
                        <thead>
                            <tr>
                                <th><span data-i18n="import_col_file_column">File column</span></th>
                                <th><span data-i18n="import_col_sample">Sample</span></th>
                                <th><span data-i18n="import_col_field">Field</span></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>

                <div id="impStepVerify" class="d-none">
                    <div class="row g-3 mb-4">
                        <?php
                        $impStats = [
                            ['label' => 'Total rows', 'label_i18n' => 'import_stat_total', 'value' => 0, 'value_id' => 'impStatTotal'],
                            ['label' => 'Valid', 'label_i18n' => 'import_stat_valid', 'value' => 0, 'value_id' => 'impStatValid'],
                            ['label' => 'Warnings', 'label_i18n' => 'import_stat_warnings', 'value' => 0, 'value_id' => 'impStatWarnings'],
                            ['label' => 'Errors', 'label_i18n' => 'import_stat_errors', 'value' => 0, 'value_id' => 'impStatErrors'],
                        ];
                        foreach ($impStats as $stat): ?>
                            <div class="col-6 col-md-3"><?php include __DIR__ . '/../partials/stat-card.php'; ?></div>
                        <?php endforeach; ?>
                    </div>

                    <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
                        <div class="imp-status-filter">
                            <label class="form-label" for="impRowStatus" data-i18n="import_show_rows">Show rows</label>
                            <select class="form-select" id="impRowStatus" data-option-keys="filter_all,import_row_error,import_row_warning,import_row_valid,import_row_pending" data-option-values="all,error,warning,valid,pending"></select>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <span class="text-muted" id="impEditedHint"></span>
                            <button type="button" class="btn btn-outline-secondary" id="impRevalidate" data-i18n="import_revalidate">Re-validate</button>
                        </div>
                    </div>

                    <table class="table table-hover w-100" id="tb_import_rows">
                        <thead></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="impPrimary" data-i18n="import_continue">Continue</button>
                <button type="button" class="btn btn-outline-secondary" id="impDiscard" data-i18n="import_discard">Discard</button>
            </div>
        </div>
    </div>
</div>

<script src="<?=asset('public/js/import/index.js')?>"></script>
