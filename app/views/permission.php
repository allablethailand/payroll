<!-- 2026-08-26, explicit request: "ปรับปรุงหน้า No Permission...ให้ใหม่ให้เข้ากับธีมของระบบ" -- was a bare,
     unstyled placeholder ("Permission Denine", literal typo, zero CSS) rendered through the normal
     header/footer layout. Redesigned as a centered error card matching this app's own
     .page-header-card/glassmorphism/brand-orange conventions instead of a generic Bootstrap alert. -->
<div class="container container-body">
  <div class="error-page-wrap">
    <div class="error-page-card">
      <div class="error-page-icon"><i class="fa-solid fa-lock"></i></div>
      <h1 class="error-page-code">403</h1>
      <h5 class="error-page-title" data-i18n="no_permission_title">Access Denied</h5>
      <p class="error-page-desc" data-i18n="no_permission_desc">You don't have permission to view this page. If you think this is a mistake, please contact your administrator.</p>
      <a href="<?=BASE_URL?>/dashboard" class="btn btn-primary error-page-btn"><i class="fa-solid fa-house me-1"></i><span data-i18n="back_to_dashboard">Back to Dashboard</span></a>
    </div>
  </div>
</div>
