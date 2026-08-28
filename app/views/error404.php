<!-- 2026-08-26, explicit request: "ปรับปรุงหน้า...404 ให้ใหม่ให้เข้ากับธีมของระบบ" -- Router::notFound()
     used to just `echo '404 - Not Found'` with no HTML/layout/CSS at all. Shares the same
     .error-page-* styling as app/views/permission.php's redesigned Access Denied card. -->
<div class="container container-body">
  <div class="error-page-wrap">
    <div class="error-page-card">
      <div class="error-page-icon"><i class="fa-solid fa-map-signs"></i></div>
      <h1 class="error-page-code">404</h1>
      <h5 class="error-page-title" data-i18n="page_not_found_title">Page Not Found</h5>
      <p class="error-page-desc" data-i18n="page_not_found_desc">The page you're looking for doesn't exist or may have been moved.</p>
      <a href="<?=BASE_URL?>/dashboard" class="btn btn-primary error-page-btn"><i class="fa-solid fa-house me-1"></i><span data-i18n="back_to_dashboard">Back to Dashboard</span></a>
    </div>
  </div>
</div>
