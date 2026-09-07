<div class="container container-body">
  <nav aria-label="breadcrumb">
    <h5 class="payroll-breadcrumb mt-5 mb-5">
      <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
      <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
      <span class="bc-current" data-i18n="data_sync_menu">Data Sync</span>
    </h5>
  </nav>
  <div class="page-header-card mb-4">
    <div class="page-header-card-icon"><i class="fa-solid fa-rotate"></i></div>
    <div class="page-header-card-body">
      <h5 class="page-header-card-title" data-i18n="data_sync_menu">Data Sync</h5>
      <p class="page-header-card-desc" data-i18n="data_sync_description">Pull department, position, shift, branch, team, and holiday master data from Origami. Employee sync has its own page (Employee List → Sync Employee from Origami).</p>
    </div>
  </div>

  <div id="dsConnectionAlert" class="alert alert-warning d-none mb-4">
    <i class="fa-solid fa-triangle-exclamation me-1"></i>
    <span id="dsConnectionAlertText"></span>
  </div>

  <!-- 2026-09-02, split into 2 top-level tabs (explicit request: "แยกเป็น 2 Tab คือ Tab ที่กด Sync
       และ Tab ประวัติการ Sync") -- .setup-tabs/.setup-menu per this app's own Tab convention
       (top-level page tab, plain Bootstrap nav-tabs styling, see CLAUDE.md's own Tab section). -->
  <ul class="nav nav-tabs flex-nowrap scrollable-tabs setup-tabs" id="dataSyncTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link setup-menu active" id="ds-sync-tab" data-bs-toggle="tab" data-bs-target="#ds-sync-pane" type="button" role="tab" aria-controls="ds-sync-pane" aria-selected="true">
        <i class="fa-solid fa-rotate me-2"></i><span data-i18n="data_sync_tab_sync">Sync</span>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link setup-menu" id="ds-history-tab" data-bs-toggle="tab" data-bs-target="#ds-history-pane" type="button" role="tab" aria-controls="ds-history-pane" aria-selected="false">
        <i class="fa-solid fa-clock-rotate-left me-2"></i><span data-i18n="data_sync_tab_history">Sync History</span>
      </button>
    </li>
  </ul>
  <div class="tab-content border-top-0 bg-white rounded-bottom mb-5 mt-0 p-3" style="border-top-left-radius:0;border-top-right-radius:0;">
    <div class="tab-pane fade show active" id="ds-sync-pane" role="tabpanel" aria-labelledby="ds-sync-tab" tabindex="0">
      <div class="d-flex justify-content-end align-items-center gap-2 mb-3">
        <!-- 2026-09-02, explicit request: "ตอน Sync อยากให้มี % บอกด้วยครับ" -- "Sync All" walks the 6
             entity types one at a time from the client (api/master-data-sync.sync-one per type)
             instead of the single all-in-one api/master-data-sync.sync-all call, so this bar
             reflects REAL step progress (N of 6 done), not a fake animation. See data-sync.js's own
             dsSyncAll() docblock. -->
        <div class="ds-progress-wrap align-items-center" id="dsSyncAllProgressWrap" style="display:none;">
          <div class="progress" style="height:6px; width:160px;">
            <div class="progress-bar" role="progressbar" id="dsSyncAllProgressBar" style="width:0%; background-color:#FF9900;"></div>
          </div>
          <span class="small text-muted ms-2" id="dsSyncAllProgressLabel">0%</span>
        </div>
        <button type="button" class="btn btn-outline-brand" id="btnSyncAllMasterData">
          <i class="fa-solid fa-rotate me-1"></i><span data-i18n="sync_all">Sync All</span>
        </button>
      </div>

      <div class="row g-3" id="dsEntityCards">
        <!-- Rendered by data-sync.js -->
      </div>
    </div>

    <div class="tab-pane fade" id="ds-history-pane" role="tabpanel" aria-labelledby="ds-history-tab" tabindex="0">
      <!-- 2026-09-02, explicit request: "ในประวัติให้มี Filter ด้วย" -- same .station-filter component
           every other history-style table in this app already uses (see Employee List's own Login
           History tab for the identical pattern this is copied from). Excel-style per-column
           filters (entity_type/status, already existing before this redesign) stay on the table
           header itself -- this row is for the date range they can't express. -->
      <div class="station-filter mb-2" id="dsHistoryStationFilter">
        <i class="fa-solid fa-filter me-1"></i><span class="station-filter-label" data-i18n="label_filter">Filter</span>
        <button type="button" class="station-filter-toggle" id="dsHistoryStationFilterToggle" title="Toggle filter">
          <i class="fas fa-chevron-up"></i>
        </button>
        <div class="station-filter-body">
          <div class="row g-2">
            <div class="col-6 col-md-3">
              <label class="form-label mb-1"><span data-i18n="filter_date_from">From</span></label>
              <div class="input-group">
                <input type="text" class="form-control datepicker" id="dsHistoryFilterDateFrom" autocomplete="off">
                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label mb-1"><span data-i18n="filter_date_to">To</span></label>
              <div class="input-group">
                <input type="text" class="form-control datepicker" id="dsHistoryFilterDateTo" autocomplete="off">
                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="station-filter-clear-row d-none" id="dsHistoryFilterClearRow">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnClearDsHistoryFilter">
          <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
        </button>
      </div>
      <div class="table-responsive">
        <table id="tb_data_sync_history" class="table table-hover align-middle w-100">
          <thead>
            <tr>
              <th data-i18n="data_sync_entity_type">Entity Type</th>
              <th data-i18n="status">Status</th>
              <th data-i18n="data_sync_total">Total</th>
              <th data-i18n="data_sync_success">Success</th>
              <th data-i18n="data_sync_error">Error</th>
              <th data-i18n="data_sync_synced_by">Synced By</th>
              <th data-i18n="data_sync_started_at">Started</th>
              <th data-i18n="data_sync_completed_at">Completed</th>
              <th data-i18n="actions">Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script src="<?=asset('public/js/setup/data-sync.js')?>"></script>
<script>
$(document).ready(function () {
    if (typeof initDataSyncPage === 'function') { initDataSyncPage(); }
});
</script>
