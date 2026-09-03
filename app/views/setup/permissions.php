<div class="container container-body">
  <nav aria-label="breadcrumb">
    <h5 class="payroll-breadcrumb mt-5 mb-5">
      <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
      <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
      <span class="bc-current" data-i18n="permissions_menu">Permissions</span>
    </h5>
  </nav>
  <!-- 2026-08-31, explicit request: "สิทธิ์การใช้งาน...อยากให้แยกออกมาเป็นอีก Menu ไปเลย และมีแยก Tab
       ตามกลุ่มภายในอีกที" -- was pill p6 inside Company Profile's Organizational Structure tab
       (see that page's own tmpl-permission-pane, now trimmed to keep only the unrelated
       Notification Preferences by Role section that happened to share the same tab). Backend
       (PermissionController::matrix()/save(), PermissionModel) is completely unchanged -- only the
       page/menu moved. Module-category grouping (the "แยก Tab ตามกลุ่มภายในอีกที" half of the
       request) is real pill-tab switching now (see permission-matrix.js's own rewrite), not just
       the previous section-header-within-one-table treatment. -->
  <div class="page-header-card mb-4">
    <div class="page-header-card-icon"><i class="fa-solid fa-shield-halved"></i></div>
    <div class="page-header-card-body">
      <h5 class="page-header-card-title" data-i18n="permissions_menu">Permissions</h5>
      <p class="page-header-card-desc" data-i18n="permissions_menu_description">Grant each role access to specific features, grouped by module.</p>
    </div>
  </div>

  <div class="bg-light rounded-3 p-2 mb-4 structure-tabs-wrap">
    <ul class="nav nav-pills flex-nowrap scrollable-tabs structure-tabs" id="permissionModuleTabs" role="tablist"></ul>
  </div>

  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
      <p class="text-muted small mb-1" data-i18n="permission_matrix_hint">Check the boxes to grant each role access. Roles are managed in the Role tab.</p>
      <!-- 2026-09-02, explicit request: "ช่วยดูเรื่อง Design ให้อีกครั้ง" -- PermissionModel::
           checkPermission()'s own $isAdmin bypass (session role='admin', a value from Origami SSO's
           role claim, unrelated to structure_roles/this matrix -- see CLAUDE.md's own note on this)
           means an admin account is completely unaffected by anything set here, which is easy to
           mistake for "this matrix isn't working" when testing as an admin. Called out explicitly so
           that confusion doesn't repeat. -->
      <p class="text-muted small mb-0"><i class="fa-solid fa-circle-info me-1"></i><span data-i18n="permission_admin_bypass_note">An account with the "Admin" role always has full access regardless of what's set here -- test with a non-admin account to see the effect of a change.</span></p>
    </div>
    <div class="d-flex gap-2">
      <button type="button" class="btn btn-light border btn-sm" id="btnCancelPermissionMatrix">
        <i class="fa-solid fa-xmark me-1"></i><span data-i18n="cancel">Cancel</span>
      </button>
      <button type="button" class="btn btn-primary btn-sm" id="btnSavePermissionMatrix">
        <i class="fa-solid fa-check me-1"></i><span data-i18n="save">Save</span>
      </button>
    </div>
  </div>
  <div id="permissionMatrixContainer" class="table-responsive"></div>
</div>

<script src="<?=asset('public/js/setup/permission-matrix.js')?>"></script>
<script>
$(document).ready(function () {
    if (typeof initPermissionMatrix === 'function') { initPermissionMatrix(); }
});
</script>
