<div class="container container-body">
    <?php
    $breadcrumb = [
        ['label' => 'Reports', 'href' => null, 'i18n' => 'reports'],
        ['label' => 'Audit & Activity Logs', 'href' => null, 'i18n' => 'audit_center_menu'],
    ];
    $description = 'Payroll run edit history and field-level data changes: who changed what, and when.';
    $description_i18n = 'audit_center_description';
    include __DIR__ . '/../partials/page-header.php';
    ?>

    <ul class="nav nav-tabs flex-nowrap scrollable-tabs setup-tabs mb-4" id="auditTopTabs" role="tablist">
        <?php if ($canRunAudit): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu<?= $activeTab === 'run' ? ' active' : '' ?>" id="audit-run-tab" data-bs-toggle="tab" data-bs-target="#audit-run-pane" type="button" role="tab" aria-controls="audit-run-pane" aria-selected="<?= $activeTab === 'run' ? 'true' : 'false' ?>"><span data-i18n="audit_tab_run">Payroll Runs</span></button>
        </li>
        <?php endif; ?>
        <?php if ($canAuditLog): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu<?= $activeTab === 'general' ? ' active' : '' ?>" id="audit-general-tab" data-bs-toggle="tab" data-bs-target="#audit-general-pane" type="button" role="tab" aria-controls="audit-general-pane" aria-selected="<?= $activeTab === 'general' ? 'true' : 'false' ?>"><span data-i18n="audit_tab_general">General Data</span></button>
        </li>
        <?php endif; ?>
    </ul>
    <div class="tab-content" id="auditTopTabsContent">
        <?php if ($canRunAudit): ?>
        <div class="tab-pane fade<?= $activeTab === 'run' ? ' show active' : '' ?>" id="audit-run-pane" role="tabpanel" aria-labelledby="audit-run-tab" tabindex="0">
            <?php include __DIR__ . '/_run_audit_pane.php'; ?>
        </div>
        <?php endif; ?>
        <?php if ($canAuditLog): ?>
        <div class="tab-pane fade<?= $activeTab === 'general' ? ' show active' : '' ?>" id="audit-general-pane" role="tabpanel" aria-labelledby="audit-general-tab" tabindex="0">
            <?php include __DIR__ . '/_audit_log_pane.php'; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canRunAudit): ?>
<?php include __DIR__ . '/_run_audit_modal.php'; ?>
<script src="<?=asset('public/js/reports/run-audit.js')?>"></script>
<?php endif; ?>
<?php if ($canAuditLog): ?>
<script src="<?=asset('public/js/setup/audit-log.js')?>"></script>
<?php endif; ?>
