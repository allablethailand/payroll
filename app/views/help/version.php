<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="help_menu">Help</span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="version_title">Version</span>
        </h5>
    </nav>
    <div class="page-header-card mb-4">
        <div class="page-header-card-icon"><i class="fa-solid fa-code-branch"></i></div>
        <div class="page-header-card-body">
            <h5 class="page-header-card-title" data-i18n="version_title">Version</h5>
            <p class="page-header-card-desc" data-i18n="version_description">What's new in Origami Payroll, most recent first.</p>
        </div>
    </div>

    <div id="versionList" class="version-list"></div>
</div>
<script src="<?=BASE_URL?>/public/js/setup/changelog.js"></script>
