<div class="container container-body">
    <?php
    $breadcrumb = [
        ['label' => 'Help', 'href' => null, 'i18n' => 'help_menu'],
        ['label' => 'Version', 'href' => null, 'i18n' => 'version_title'],
    ];
    $description = 'What\'s new in Origami Payroll, most recent first.';
    $description_i18n = 'version_description';
    include __DIR__ . '/../partials/page-header.php';
    ?>

    <div id="versionList" class="version-list"></div>
</div>
<script src="<?=asset('public/js/setup/changelog.js')?>"></script>
