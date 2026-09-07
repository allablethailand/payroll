<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="help_menu">Help</span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="setup_guide_title">Setup Guide</span>
        </h5>
    </nav>
    <div class="page-header-card mb-4">
        <div class="page-header-card-icon"><i class="fa-solid fa-list-check"></i></div>
        <div class="page-header-card-body">
            <h5 class="page-header-card-title" data-i18n="setup_guide_title">Setup Guide</h5>
            <p class="page-header-card-desc" data-i18n="setup_guide_description">Checklist of the essential settings a company needs before running a real payroll round.</p>
        </div>
    </div>

    <div class="card-surface mb-4 sg-progress-card">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="fw-semibold" data-i18n="setup_guide_progress_label">Setup progress</span>
            <span id="sgProgressText" class="fw-bold text-primary">0%</span>
        </div>
        <div class="progress" style="height: 10px;">
            <div id="sgProgressBar" class="progress-bar bg-warning" role="progressbar" style="width: 0%"></div>
        </div>
    </div>

    <div id="sgChecklist" class="sg-checklist"></div>
</div>
<script src="<?=BASE_URL?>/public/js/setup/setup-guide.js"></script>
