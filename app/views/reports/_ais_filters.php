<?php
/**
 * Shared Annual Income Summary filter fields (Cycle/Department/Team/Branch/Role/Status) -- used by the
 * Annual Summary pane and the Monthly Withholding Tax pane so the markup exists once.
 * @var string $ais_prefix element-id prefix, e.g. 'aisFilter' -> #aisFilterCycle, #aisFilterStatus
 */
$ais_filter_fields = [
    ['Cycle', 'payroll_cycle', 'Payroll Cycle', '/api/payroll-cycle.options', null],
    ['Department', 'department', 'Department', '/api/department.get', 'department'],
    ['Team', 'team', 'Team', '/api/team.get', 'team'],
    ['Branch', 'branch', 'Branch', '/api/branch.get', 'branch'],
    ['Role', 'role', 'Role', '/api/role.get', 'role'],
];
?>
<div class="row g-3">
    <?php foreach ($ais_filter_fields as [$suffix, $i18n, $label, $api, $type]): ?>
    <div class="col-sm-2">
        <label class="form-label small mb-1" for="<?= $ais_prefix . $suffix ?>" data-i18n="<?= $i18n ?>"><?= $label ?></label>
        <select class="form-select select2-remote" id="<?= $ais_prefix . $suffix ?>" data-api="<?= $api ?>"<?= $type ? ' data-type="' . $type . '"' : '' ?>></select>
    </div>
    <?php endforeach; ?>
    <div class="col-sm-2">
        <label class="form-label small mb-1" for="<?= $ais_prefix ?>Status" data-i18n="status">Status</label>
        <select class="form-select" id="<?= $ais_prefix ?>Status" data-option-keys="status_all,status_active,status_probation,status_resigned,status_terminated" data-option-values="all,active,probation,resigned,terminated"></select>
    </div>
</div>
