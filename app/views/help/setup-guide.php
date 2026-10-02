<div class="container container-body">
    <?php
    $breadcrumb = [
        ['label' => 'Help', 'href' => null, 'i18n' => 'help_menu'],
        ['label' => 'Setup Guide', 'href' => null, 'i18n' => 'setup_guide_title'],
    ];
    $description = 'Checklist of the essential settings a company needs before running a real payroll round.';
    $description_i18n = 'setup_guide_description';
    include __DIR__ . '/../partials/page-header.php';
    ?>

    <div class="card-surface mb-4 sg-progress-card">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="fw-semibold" data-i18n="setup_guide_progress_label">Setup progress</span>
            <span id="sgProgressText" class="fw-bold">0%</span>
        </div>
        <div class="progress" style="height: 10px;">
            <div id="sgProgressBar" class="progress-bar bg-warning" role="progressbar" style="width: 0%"></div>
        </div>
    </div>

    <div id="sgChecklist" class="sg-checklist"></div>
</div>
<script src="<?=asset('public/js/setup/setup-guide.js')?>"></script>
