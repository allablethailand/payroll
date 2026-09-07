<!doctype html>
<?php
// 2026-09-04, Backlog Phase 11, T069 (dark mode), Step 1 of 3 -- stamped server-side, right here,
// BEFORE any CSS is even linked below, so there is zero client-side flash-of-wrong-theme on load
// (a purely-client-side toggle-after-page-load approach would show the light theme for one frame
// first on every single page navigation, every time, for every dark-mode user -- unacceptable for
// something this visually jarring). Session data is already available at this exact point in the
// request lifecycle (ensure_login() already ran, before any Controller::view() call reaches this
// include -- see app/core/Controller.php), so no extra DB query is needed per page load.
//
// 2026-09-05, real bug found and fixed -- the ORIGINAL version of this block treated NULL the
// same as an explicit 'system' choice (both stamped nothing, both fell through to style.css's own
// `@media (prefers-color-scheme: dark)` rule). That silently put an employee who had NEVER opened
// Settings into dark mode the instant their OS/browser happened to be set to dark -- explicit
// follow-up request: "อยากให้ Default เป็น Mode ปกติก่อน แล้วผู้ใช้เปลี่ยนเองทีหลัง" (default must be
// Light for everyone; only the employee's OWN explicit choice should change it). 'system' is now
// its own real enum value (see 2026-09-05_1_ui_theme_add_system_value.sql) distinct from NULL, so
// this is a genuine 3-way branch: 'dark' -> stamp dark; 'system' (an EXPLICIT choice to follow the
// OS) -> stamp NOTHING, letting the @media rule decide; anything else, including NULL (never
// configured) AND the explicit 'light' choice -> stamp 'light' outright. `ui_theme` is populated
// straight from the session, hydrated at login by auth/index.php and kept in sync by
// UserPreferenceController::save() -- no extra DB query needed per page load.
$userThemePref = $_SESSION['user']['ui_theme'] ?? null;
if ($userThemePref === 'dark') {
    $htmlThemeAttr = ' data-bs-theme="dark"';
} elseif ($userThemePref === 'system') {
    $htmlThemeAttr = '';
} else {
    $htmlThemeAttr = ' data-bs-theme="light"';
}
?>
<html lang="th"<?=$htmlThemeAttr?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<!-- 2026-09-03, Platform UX review Phase 3: this static tag is now only the brief pre-JS fallback
     (every page immediately overwrites it via updateDocumentTitleFromBreadcrumb() in app.js, derived
     from that same page's own breadcrumb -- see that function's own docblock) -- was a single
     hardcoded string shared by literally every page before this. -->
<title>Origami Payroll</title>
<link rel="icon" type="image/png" href="<?=BASE_URL?>/public/images/logo_vertical.png">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
<link href="<?=BASE_URL?>/node_modules/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="<?=BASE_URL?>/node_modules/@fortawesome/fontawesome-free/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?=BASE_URL?>/node_modules/sweetalert2/dist/sweetalert2.min.css">
<link rel="stylesheet" href="<?=BASE_URL?>/node_modules/datatables.net-bs5/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="<?=BASE_URL?>/node_modules/datatables.net-responsive-bs5/css/responsive.bootstrap5.min.css">
<!-- 2026-08-29 -- FixedColumns' own stylesheet (Annual Income Summary). The JS alone applies
     position:sticky to the frozen cells, but WITHOUT this file they get no z-index/opaque
     background, so scrolling columns visually paint over the "frozen" ones instead of staying
     behind them -- looked completely broken even though the JS wiring (script order in footer.php,
     window.DataTable global) was correct. This file is the actual fix. -->
<link rel="stylesheet" href="<?=BASE_URL?>/node_modules/datatables.net-fixedcolumns-bs5/css/fixedColumns.bootstrap5.min.css">
<link href="<?=BASE_URL?>/node_modules/select2/dist/css/select2.min.css" rel="stylesheet">
<link href="<?=BASE_URL?>/node_modules/select2-bootstrap-5-theme/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?=BASE_URL?>/node_modules/bootstrap-datepicker/dist/css/bootstrap-datepicker.standalone.min.css">
<link rel="stylesheet" href="<?=BASE_URL?>/node_modules/intl-tel-input/dist/css/intlTelInput.min.css">
<!-- 2026-08-26, explicit request: "ที่อยู่ให้เพิ่มสามารถปักหมุด Location บนแผนที่ได้" -- OpenStreetMap +
     Leaflet (chosen over Google Maps: free, no API key needed), same node_modules-served convention as
     every other JS dependency in this project. -->
<link rel="stylesheet" href="<?=BASE_URL?>/node_modules/leaflet/dist/leaflet.css">
<!-- 2026-09-07, explicit request: "จัดรูปแบบเนื้อหาได้" (Announcement CMS rich-text formatting) --
     Quill (snow theme -- the classic toolbar-on-top look), same node_modules-served convention as
     every other JS dependency in this project. -->
<link rel="stylesheet" href="<?=BASE_URL?>/node_modules/quill/dist/quill.snow.css">
<link rel="stylesheet" href="<?=asset('public/css/style.css')?>">
<script>
    const BASE_URL = "<?=BASE_URL?>";
    // 2026-08-29: the logged-in user's own employee id, exposed so a page editing an employee record
    // (Employee Detail) can tell whether it's currently editing the LOGGED-IN USER's own record --
    // used to live-refresh the nav profile photo (#navProfilePhoto above) right after a photo
    // upload, without waiting for the next full page navigation to re-render it server-side.
    const SESSION_EMPLOYEE_ID = <?=(int)($_SESSION['user']['employee_id'] ?? 0)?>;
    // 2026-08-30, Phase 7 (T037/T038/T039) -- session-guard.js's own idle-timer/heartbeat/popup
    // needs both of these: where to send the user once their session ends (Origami's own base URL,
    // not this app's /auth -- there is nothing to "log back into here" once the session is gone,
    // only Origami itself), and the SAME 30-minute figure app/helpers/helpers.php's ensure_login()
    // enforces server-side (this is the client-side COUNTDOWN only -- the server-side check is what
    // actually matters, see that function's own docblock; kept as one shared number so the two
    // can never quietly drift apart).
    const ORIGAMI_BASE_URL = "<?=ORIGAMI_BASE_URL?>";
    const SESSION_IDLE_TIMEOUT_SECONDS = <?=SESSION_IDLE_TIMEOUT_SECONDS?>;
    <?php
    // 2026-08-28, explicit request: "ถ้าไม่ใช่บริษัทที่มาจาก Origami ปุ่ม Sync จะไม่ขึ้น รวมถึงใน
    // Process ด้วย จะไม่มีข้อมูลรอบที่ดึงมา" -- every Sync-from-Origami button (Employee/Holiday/
    // Department/Position/Team) and the Payroll Process page's "Pending Pull" station must hide
    // entirely for a company that isn't linked to the relevant Origami integration, computed ONCE
    // here (not per-page) so every page's own JS can just read a plain boolean instead of each
    // re-querying `companies` itself. TWO SEPARATE flags, deliberately NOT one -- `ref_id` (Origami
    // HR link, what Employee/Holiday/Department/Position/Team Sync all actually depend on) and
    // `origami_payroll_comp_code` (Origami Payroll's own, unrelated ingest mapping, what "Pending
    // Pull" on the Process page actually depends on) are genuinely different integrations with
    // independent id spaces -- a company could plausibly have one configured without the other, so
    // collapsing them into a single flag would risk hiding a feature that could actually work.
    $compIdForOrigamiFlags = (int)(getCompId() ?? 0);
    $isOrigamiHrLinked = false;
    $isOrigamiPayrollLinked = false;
    // 2026-09-04, Backlog Phase 11, T068 -- the logged-in company's own logo_path (Company Profile's
    // own "Company Logo" upload card, see CLAUDE.md's "Logo upload card redesign" section), shown in
    // the top navbar ALONGSIDE the existing Origami Payroll branding -- reuses this SAME query
    // (already fetching ref_id/origami_payroll_comp_code/currency_code for this exact compId below)
    // rather than a second DB round trip, matching this file's own established "fetch once here, not
    // per-page" convention for company-level display data.
    $companyLogoPath = null;
    $companyLogoTitle = '';
    // 2026-09-03, Manual Entry / Platform UX review Phase 5 (fee currency), Option A -- exposed here
    // (not fetched per-page via AJAX) for the exact same reason IS_ORIGAMI_HR_LINKED/
    // IS_ORIGAMI_PAYROLL_LINKED already are: every page's JS can read a plain global instead of each
    // re-querying `companies` itself. Used by app.js's applyCurrencyLabel() to fill in every
    // `.currency-code-label` span (see that function's own docblock for which fields these are).
    $companyCurrencyCode = 'THB';
    if ($compIdForOrigamiFlags > 0) {
        $stmtOrigamiFlags = Database::getInstance()->pdo->prepare("SELECT ref_id, origami_payroll_comp_code, currency_code, logo_path, local_name, company_legal_name FROM companies WHERE id = :id");
        $stmtOrigamiFlags->execute([':id' => $compIdForOrigamiFlags]);
        $companyOrigamiFlags = $stmtOrigamiFlags->fetch(PDO::FETCH_ASSOC);
        $isOrigamiHrLinked = !empty($companyOrigamiFlags['ref_id']);
        $isOrigamiPayrollLinked = !empty($companyOrigamiFlags['origami_payroll_comp_code']);
        $companyCurrencyCode = !empty($companyOrigamiFlags['currency_code']) ? $companyOrigamiFlags['currency_code'] : 'THB';
        $companyLogoPath = !empty($companyOrigamiFlags['logo_path']) ? $companyOrigamiFlags['logo_path'] : null;
        $companyLogoTitle = (string)($companyOrigamiFlags['local_name'] ?? $companyOrigamiFlags['company_legal_name'] ?? '');
    }
    ?>
    const IS_ORIGAMI_HR_LINKED = <?=$isOrigamiHrLinked ? 'true' : 'false'?>;
    const IS_ORIGAMI_PAYROLL_LINKED = <?=$isOrigamiPayrollLinked ? 'true' : 'false'?>;
    const COMPANY_CURRENCY_CODE = "<?=htmlspecialchars($companyCurrencyCode, ENT_QUOTES)?>";
</script>
<?php
// 2026-08-28, explicit request: "ถ้าสมมุติ Set สิทธิ์ว่าไม่สามารถทำรายการนี้ได้ ถ้าเป็นทั้ง Menu Login เข้ามา
// ก็ทั้ง Menu hidden ไปเลย" -- when a role has zero access to an entire menu's worth of
// functionality, that sidebar entry point should disappear on login rather than staying visible
// with every action inside it just refusing. Scoped to the ONE sidebar link that maps 1:1 onto a
// single PermissionModel-gated feature with no ungated functionality mixed into the same page
// (`setup/document-approval` -> ApprovalWorkflowController, gated end-to-end by
// `approval_workflow.view`/`.manage`, see CLAUDE.md's Approval Workflow section) -- NOT applied to
// "Time & Leave" -> Setup & Rules, since that single page mixes gated tabs (Holiday/Leave Type)
// with ungated ones (Shift/OT Rate/Work Location); hiding that whole submenu link on a
// Holiday/Leave-Type-only denial would incorrectly hide the ungated tabs too. Deliberately reads
// straight from PermissionModel rather than duplicating its query -- same coarse-gate convention
// every controller already uses (see PermissionModel::checkPermission()'s own docblock: admin
// bypasses, an employee with no role_id assigned at all simply has no grants, matching this same
// request's other half about role_id becoming optional on the Employee form).
$canViewApprovalWorkflowMenu = true;
if ($compIdForOrigamiFlags > 0) {
    $menuUserId = (int)($_SESSION['user']['employee_id'] ?? 0);
    $menuIsAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
    $canViewApprovalWorkflowMenu = (new PermissionModel())->checkPermission($menuUserId, 'approval_workflow.view', $menuIsAdmin, $compIdForOrigamiFlags)['allowed'];
}

// 2026-08-31, explicit request: "สิทธิ์การใช้งาน...อยากให้แยกออกมาเป็นอีก Menu ไปเลย" -- same
// single-purpose-page hide-the-whole-entry gate as $canViewApprovalWorkflowMenu directly above,
// gated by rbac.view (the same permission PermissionController's own matrix() action requires).
// 2026-09-03, Phase 3 Stage 3: swapped off the retired coarse `.manage`.
$canViewPermissionsMenu = true;
if ($compIdForOrigamiFlags > 0) {
    $menuUserId = (int)($_SESSION['user']['employee_id'] ?? 0);
    $menuIsAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
    $canViewPermissionsMenu = (new PermissionModel())->checkPermission($menuUserId, 'rbac.view', $menuIsAdmin, $compIdForOrigamiFlags)['allowed'];
}

// 2026-09-03, Platform Hardening Phase 6 pilot -- same single-purpose-page hide-the-whole-entry
// pattern as $canViewPermissionsMenu directly above, gated by audit_log.view.
$canViewAuditLogMenu = true;
if ($compIdForOrigamiFlags > 0) {
    $menuUserId = (int)($_SESSION['user']['employee_id'] ?? 0);
    $menuIsAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
    $canViewAuditLogMenu = (new PermissionModel())->checkPermission($menuUserId, 'audit_log.view', $menuIsAdmin, $compIdForOrigamiFlags)['allowed'];
}

// 2026-09-04, Backlog Phase 10, T057 -- same single-purpose-page hide-the-whole-entry pattern as
// $canViewAuditLogMenu directly above, gated by announcement.manage. This is the CMS management
// entry only -- every employee (regardless of this permission) can still see their OWN announcements
// via the Dashboard widget + Notifications + the plain /announcements "my list" page, none of which
// go through this sidebar item at all.
$canViewAnnouncementMenu = true;
if ($compIdForOrigamiFlags > 0) {
    $menuUserId = (int)($_SESSION['user']['employee_id'] ?? 0);
    $menuIsAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
    $canViewAnnouncementMenu = (new PermissionModel())->checkPermission($menuUserId, 'announcement.manage', $menuIsAdmin, $compIdForOrigamiFlags)['allowed'];
}

// 2026-09-02, explicit request: "ซ่อนเมนู Report ด้วยเลยครับ" (following up on ReportsController now
// being gated by payroll_run.view end-to-end, see that controller's own requireViewAccess()) --
// UNLIKE $canViewApprovalWorkflowMenu/$canViewPermissionsMenu above, the "Reports" menu item is a
// 3-row SUBMENU spanning 2 genuinely different permissions, not one single-purpose page: "Generate
// Reports" (/reports) and "Payroll Run Audit" (/reports/run-audit) are both ReportsController,
// gated by payroll_run.view; "Annual Income Summary" (/reports/annual-summary) is a SEPARATE
// controller (AnnualIncomeSummaryController) gated by its own independent annual_income_summary.view
// permission, unrelated to payroll_run.view. Hiding the whole submenu behind payroll_run.view alone
// would incorrectly hide Annual Income Summary from a role deliberately granted ONLY
// annual_income_summary.view (a real, distinct, already-existing permission) -- so each row is
// gated by its OWN applicable permission below, and the top-level "Reports" parent only hides when
// NEITHER sub-permission is held (nothing left underneath it to show).
$canViewReportsGenerateMenu = true;
$canViewAnnualIncomeSummaryMenu = true;
if ($compIdForOrigamiFlags > 0) {
    $menuUserId = (int)($_SESSION['user']['employee_id'] ?? 0);
    $menuIsAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
    $canViewReportsGenerateMenu = (new PermissionModel())->checkPermission($menuUserId, 'payroll_run.view', $menuIsAdmin, $compIdForOrigamiFlags)['allowed'];
    $canViewAnnualIncomeSummaryMenu = (new PermissionModel())->checkPermission($menuUserId, 'annual_income_summary.view', $menuIsAdmin, $compIdForOrigamiFlags)['allowed'];
}
$canViewReportsMenu = $canViewReportsGenerateMenu || $canViewAnnualIncomeSummaryMenu;

// 2026-08-29, explicit request: "ให้ดึงรูปไปแสดงที่ header ด้วยครับ" -- the logged-in user's own profile
// photo (employees.profile_photo_path) shown in the top-right nav dropdown, which previously always
// hardcoded the generic placeholder (userNoImage.jpg) regardless of who was logged in. A direct,
// lightweight query here (same inline-model-in-this-file convention as $canViewApprovalWorkflowMenu
// just above) rather than a full EmployeeModel::get() call, which pulls a lot more than one column.
$navProfilePhotoPath = null;
$navUserId = (int)($_SESSION['user']['employee_id'] ?? 0);
if ($navUserId > 0) {
    $navPhotoStmt = Database::getInstance()->pdo->prepare("SELECT profile_photo_path FROM `employees` WHERE id = :id AND deleted_at IS NULL");
    $navPhotoStmt->execute([':id' => $navUserId]);
    $navProfilePhotoPath = $navPhotoStmt->fetchColumn() ?: null;
}

// 2026-09-07, explicit request: "ทางลัด วางอยู่ล่างเกินไป ใช้งานไม่สะดวกครับ...ปรับเป็นให้อยู่บน header ไปเลย
// ให้เรียงอยู่ก่อนหน้า notification โดยให้ผู้ใช้เลือกได้ว่าจะโชว์ หรือไม่โชว์เมนูไหน เลือกได้ทั้งเมนู และ sub menu
// แต่การแสดงผลต้องไม่ล้นจอ...ที่เหลือเป็นปุ่ม more" -- Quick Links moves out of the Dashboard's own
// bottom-of-sidebar card into the navbar itself, right before .nav-notif-dropdown below. The catalog
// (every sidebar link, top-level AND submenu, permission-filtered) and this employee's own saved
// selection are computed ONCE here and handed to the client as plain JSON -- `public/js/quick-
// links.js` does the actual rendering/overflow-measurement/Customize-modal wiring, so this file
// stays pure data. See UserPreferenceModel::quickLinkCatalog()'s own docblock for why the catalog is
// a hand-maintained mirror of the sidebar below, not generated from it.
$quickLinkCatalog = [];
$quickLinkSelectedKeys = [];
if ($compIdForOrigamiFlags > 0 && $navUserId > 0) {
    $quickLinkCatalog = UserPreferenceModel::quickLinkCatalog($compIdForOrigamiFlags, $navUserId, ($_SESSION['user']['role'] ?? '') === 'admin');
    $quickLinkCatalogKeys = array_column($quickLinkCatalog, 'key');
    $quickLinkSavedKeys = (new UserPreferenceModel())->getQuickLinks($navUserId, $compIdForOrigamiFlags);
    $quickLinkSelectedKeys = array_values(array_intersect($quickLinkSavedKeys ?? UserPreferenceModel::defaultQuickLinkKeys(), $quickLinkCatalogKeys));
}
?>
<script>
    const QUICK_LINK_CATALOG = <?=json_encode($quickLinkCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>;
    const QUICK_LINK_SELECTED = <?=json_encode($quickLinkSelectedKeys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>;
</script>
</head>
<body>
<script src="<?=BASE_URL?>/node_modules/jquery/dist/jquery.min.js"></script>
<script src="<?=asset('public/js/app.js')?>"></script>
<script src="<?=asset('public/js/alert.js')?>"></script>
<!-- 2026-09-04, Backlog Phase 11, T065 -- escapeHtml()/escapeAttr()/fmtNum(), replacing ~30
     near-identical per-file copies (escapeHtmlPc/escapeHtmlDn/escapeHtmlTs/etc.) that had
     accumulated across this app. Loaded early/globally so every page-specific script below can use
     these with zero per-page setup, same convention as input.js. -->
<script src="<?=asset('public/js/format-helpers.js')?>"></script>
<!-- 2026-08-30, Phase 7 (T037/T038/T039) -- idle-timeout/duplicate-login popup, see the file's own
     top-of-file docblock. Loaded on every logged-in page via this shared layout; auth/index.php and
     auth/switch.php never include this file at all (standalone scripts, no session to guard yet). -->
<script src="<?=asset('public/js/session-guard.js')?>"></script>
<script src="<?=asset('public/js/input.js')?>"></script>
<script src="<?=asset('public/js/table-column-filter.js')?>"></script>
<!-- 2026-09-04, Backlog Phase 10, T055 -- generic reusable "Assign to Department/Position/Team/
     Employee" widget driving the shared #entityAssignModal in modals.php. Loaded globally (same as
     the modal itself) so any future page can call openAssignModal() with zero per-page setup -- see
     EntityAssignmentModel's own docblock for the full architecture. -->
<script src="<?=asset('public/js/setup/assign-widget.js')?>"></script>
<script src="<?=asset('public/js/notifications.js')?>"></script>
<script src="<?=asset('public/js/quick-links.js')?>"></script>
<nav class="origami-navbar">
    <div class="nav-container">
        <div class="nav-left">
            <button class="nav-btn-hamberger" type="button">
                <img src="<?=BASE_URL?>/public/images/HambergerIcon.svg" alt="Menu">
            </button>
            <a class="nav-logo" href="<?=BASE_URL?>/dashboard">
                <img src="<?=BASE_URL?>/public/images/logo_horizontal.png" alt="Origami Logo">
            </a>
            <?php if ($companyLogoPath): ?>
            <!-- 2026-09-04, Backlog Phase 11, T068 -- the logged-in company's own logo, alongside
                 (not replacing) the Origami Payroll branding above. A divider marks them as two
                 distinct identities sharing the bar rather than implying the company logo IS the
                 Origami logo. object-fit:contain + a fixed max-height/max-width box (CSS,
                 .nav-company-logo) keeps an oddly-shaped/oversized uploaded image from breaking the
                 navbar's own fixed height or pushing .nav-right's icons around, same sizing
                 discipline Company Profile's own .cp-logo-preview-box already applies to this same
                 logo_path elsewhere in the app. Absent entirely (not a broken-image icon or an empty
                 gap) for the common case of a company that hasn't uploaded one yet. -->
            <span class="nav-logo-divider" aria-hidden="true"></span>
            <span class="nav-company-logo" title="<?=htmlspecialchars($companyLogoTitle, ENT_QUOTES, 'UTF-8')?>">
                <img src="<?=BASE_URL?>/<?=htmlspecialchars($companyLogoPath, ENT_QUOTES, 'UTF-8')?>" alt="Company Logo">
            </span>
            <?php endif; ?>
        </div>
        <div class="nav-right">
            <!-- 2026-09-07, explicit request: "ทางลัด...ปรับเป็นให้อยู่บน header ไปเลย ให้เรียงอยู่ก่อนหน้า
                 notification" -- moved out of the Dashboard's own Quick Links card (dashboard.php),
                 now here, first child of .nav-right so it always renders before the notification
                 bell. `#navQuickLinksBar` is the SHRINKING half (icon buttons, JS decides how many
                 fit) and `#navQuickLinksMoreBtn`'s dropdown is the FIXED half (always fully visible,
                 holds whatever overflowed + a permanent "Customize Quick Links" entry) -- see
                 public/js/quick-links.js's own docblock for the measure-and-overflow algorithm and
                 why it's real DOM measurement, not a guessed pixel budget. -->
            <div class="nav-quicklinks-wrap" id="navQuickLinksWrap">
                <div class="nav-quicklinks" id="navQuickLinksBar"></div>
                <div class="nav-quicklinks-more-dropdown">
                    <button type="button" class="nav-quicklinks-more-btn" id="navQuickLinksMoreBtn" title="More" data-i18n-title="quick_links_more">
                        <img src="<?=BASE_URL?>/public/images/MORE.svg" alt="More">
                    </button>
                    <ul class="nav-quicklinks-more-menu" id="navQuickLinksMoreMenu"></ul>
                </div>
            </div>
            <!-- 2026-08-29, explicit request: "บน header มี icon noti อยู่ ช่วยวางระบบการแจ้งเตือนพร้อมทั้ง
                 Design การมองเห็นหน่อยครับ โดยเป็นของใครของมัน...และสามารถคลิกจาก item นั้นแล้วไปหน้านั้นได้เลย
                 โดย Slide ลงมาสุดท้ายแล้วค่อยๆทยอยโหลด และมีเปิดเพื่อดูทั้งหมดเป็นอีกหน้า" -- this was a dead
                 href="#" link before now. Same .nav-hub-dropdown active-class-toggle convention as
                 the hub/language dropdowns right next to it (public/js/app.js). List content itself
                 is populated/paginated client-side (public/js/notifications.js) -- see
                 NotificationModel's own top-of-file docblock for the full notification-type
                 analysis and per-user read-state design. -->
            <div class="nav-notif-dropdown">
                <button class="nav-notif-btn" type="button" id="notifBellBtn" title="Notifications">
                    <img src="<?=BASE_URL?>/public/images/Bell.svg" alt="Notifications">
                    <span class="nav-notif-badge d-none" id="notifBadge">0</span>
                </button>
                <div class="nav-notif-menu" id="notifMenu">
                    <div class="nav-notif-menu-header">
                        <span data-i18n="notifications">Notifications</span>
                        <button type="button" id="notifMarkAllReadBtn" data-i18n="notif_mark_all_read">Mark all as read</button>
                    </div>
                    <div class="nav-notif-menu-list" id="notifMenuList">
                        <div class="nav-notif-empty d-none" id="notifMenuEmpty" data-i18n="notif_empty">No notifications yet.</div>
                    </div>
                    <div class="nav-notif-menu-footer">
                        <a href="<?=BASE_URL?>/notifications" data-i18n="notif_view_all">View All</a>
                    </div>
                </div>
            </div>
            <div class="nav-hub-dropdown">
                <button class="nav-hub-btn" type="button" title="Switch application">
                    <img src="<?=BASE_URL?>/public/images/HUB.svg" alt="Origami Hub">
                </button>
                <ul class="nav-hub-menu" id="hubMenu">
                    <?php
                        $origamiApps = array_filter($_SESSION['origami_apps'] ?? [], fn($a) => (int)($a['app_active'] ?? 0) !== 1);
                        $switchToken = $_SESSION['origami_switch_token'] ?? '';
                    ?>
                    <?php if (empty($origamiApps) || $switchToken === ''): ?>
                        <li class="nav-hub-empty">No other applications</li>
                    <?php else: ?>
                        <?php foreach ($origamiApps as $app): ?>
                            <li class="nav-hub-tile">
                                <!-- 2026-08-26, explicit bug report: "Switch App กลับไปใช้งาน Origami
                                     ... Session ไม่ตัด" -- this used to link straight to Origami's own
                                     switch URL, so Payroll's OWN session never got torn down when
                                     leaving via the hub. Routes through auth/switch.php first now,
                                     which destroys this session THEN redirects on to Origami (see
                                     that file's own docblock) -- also keeps the raw switch token out
                                     of this page's own rendered HTML, unlike the old direct link. -->
                                <a href="<?=BASE_URL?>/auth/switch?app=<?=urlencode((string)($app['app_key'] ?? ''))?>">
                                    <span class="nav-hub-tile-icon">
                                        <img src="<?=htmlspecialchars((string)($app['app_logo'] ?? ''), ENT_QUOTES, 'UTF-8')?>" alt="" onerror="this.onerror=null;this.src='<?=BASE_URL?>/public/images/origami_logo.png';">
                                    </span>
                                    <span class="nav-hub-tile-label"><?=htmlspecialchars(trim((string)($app['app_name'] ?? '')), ENT_QUOTES, 'UTF-8')?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                        <?php
                            // Grid is always exactly 3 columns wide and the corner-rounding CSS
                            // (:first-child / :nth-child(3) / :nth-last-child(3) / :last-child)
                            // assumes a full last row -- pad it out with inert, unclickable filler
                            // tiles so the bottom corners always land on the actual last row
                            // instead of wherever the item count % 3 happens to put them.
                            $remainder = count($origamiApps) % 3;
                            $fillersNeeded = $remainder > 0 ? 3 - $remainder : 0;
                        ?>
                        <?php for ($i = 0; $i < $fillersNeeded; $i++): ?>
                            <li class="nav-hub-tile nav-hub-tile-filler" aria-hidden="true">
                                <span class="nav-hub-tile-icon"><img src="<?=BASE_URL?>/public/images/origami_logo.png" alt="" style="opacity:0;"></span>
                                <span class="nav-hub-tile-label">&nbsp;</span>
                            </li>
                        <?php endfor; ?>
                    <?php endif; ?>
                </ul>
            </div>
            <div class="nav-lang-dropdown">
                <button class="nav-lang-btn" type="button">
                    <img class="current-flag" src="<?=BASE_URL?>/public/flags/gb.png" width="15" alt="EN flag">
                    <span class="lang-text text-current-lang">EN</span>
                </button>
                <ul class="nav-lang-menu" id="languageMenu"></ul>
            </div>
            <!-- 2026-08-29, explicit request: "ส่วนที่ปรับขนาดตัวอักษรอยู่ตรงไหนครับ...หรือเพิ่มไปในตั้งค่า
                 อีกเมนูตรงรูป Profile กดลงมาแล้วเป็น Setting แล้วเปิด Modal ให้ตั้งค่า" -- this profile
                 icon was previously a dead `href="#"` link (see the Origami SSO section in
                 CLAUDE.md's own history: "ยังไม่มีปุ่ม Logout แบบทั่วไป...ถ้าต้องการ logout button แยก
                 ต่างหากใน .nav-profile-link (ที่ยังเป็น dead href="#" อยู่)"). Now a dropdown, same
                 active-class-toggle convention as .nav-hub-dropdown/.nav-lang-dropdown right above
                 (see public/js/app.js) -- ONLY "Settings" for now (opens #userSettingsModal, moved
                 to app/views/layout/modals.php as of 2026-08-30's modal consolidation),
                 per this request's own scope; a real Logout entry was NOT asked for here and would
                 need its own separate request even though the backend teardown pattern already
                 exists in auth/switch.php if that's wanted later. -->
            <div class="nav-profile-dropdown">
                <button class="nav-profile-btn" type="button">
                    <div class="profile-img-box">
                        <img id="navProfilePhoto" src="<?=$navProfilePhotoPath ? BASE_URL . '/' . $navProfilePhotoPath : BASE_URL . '/public/images/userNoImage.jpg'?>" alt="User Profile">
                    </div>
                </button>
                <ul class="nav-profile-menu" id="profileMenu">
                    <li>
                        <a href="javascript:void(0);" id="btnOpenUserSettings" data-bs-toggle="modal" data-bs-target="#userSettingsModal">
                            <i class="fa-solid fa-gear"></i>
                            <span data-i18n="user_settings_menu">Settings</span>
                        </a>
                    </li>
                    <!-- 2026-09-05, Backlog Phase 13 -- "view again" (not the forced login-gate
                         modal, same #termsModal content reused in a non-forced mode, see
                         terms-and-conditions.js's own openTermsModal(forced) param) and the
                         self-service login-history view. -->
                    <li>
                        <a href="javascript:void(0);" id="btnOpenTermsView" data-bs-toggle="modal" data-bs-target="#termsModal">
                            <i class="fa-solid fa-file-contract"></i>
                            <span data-i18n="terms_and_conditions_menu">Terms and Conditions</span>
                        </a>
                    </li>
                    <li>
                        <a href="javascript:void(0);" id="btnOpenAccessHistory" data-bs-toggle="modal" data-bs-target="#systemAccessHistoryModal">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                            <span data-i18n="system_access_history_menu">System Access History</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</nav>
<!-- 2026-08-29, explicit request: per-user Font Size (S/M/L, "อาจเป็น slide bar ให้เลือกเลื่อนเอา"),
     persisted server-side (see UserPreferenceModel's own docblock). Loaded on every page via
     header.php itself (not a per-page include) since the trigger (profile dropdown) is also
     global. Language was ALSO in this modal originally (same UserPreferenceModel/persistence),
     but removed same-day per explicit follow-up: "ตัวเปลี่ยนภาษาตัดออกจากใน modal setting ครับ
     เพราะมีใน header อยู่แล้ว" -- the top-right nav-lang-dropdown switcher already covers it, and
     already calls persistUserPreferences() itself (see changeLanguage() in app.js), so nothing
     about server-side language persistence was lost by removing this section -- it just no longer
     has a SECOND, redundant control for the same thing. userSettingsModal itself moved to
     app/views/layout/modals.php as of 2026-08-30's modal consolidation. -->
<aside class="origami-sidebar" id="origamiSidebar">
    <!-- 2026-08-23, explicit request ("ใน Menu อยากให้เพิ่มช่องในการค้นหา Menu ในกรณีที่ Menu เยอะๆ") --
         filters .menu-item/.submenu-link by their visible text as you type (public/js/app.js's
         registerSidebarMenuSearch()). A top-level item with a matching submenu item stays visible
         and auto-expands even if its OWN label doesn't match, so you can search by the specific
         page name ("Payroll Configuration") without knowing which top-level group it lives under. -->
    <div class="sidebar-search">
        <i class="fas fa-magnifying-glass sidebar-search-icon"></i>
        <input type="text" class="sidebar-search-input" id="sidebarMenuSearch" data-i18n="menu_search_placeholder" placeholder="Search menu...">
    </div>
    <ul class="sidebar-menu" id="sidebarMenuList">
        <li class="menu-item">
            <a href="<?=BASE_URL?>/dashboard" class="menu-link">
                <span class="menu-icon">
                    <img src="<?=BASE_URL?>/public/images/menu/DASHBOARD.SVG" alt="Dashboard">
                </span>
                <span class="menu-text" data-i18n="dashboard">Dashboard</span>
            </a>
        </li>
        <!-- 2026-09-02, explicit request: "ลุยเลยครับ แล้วก็ประวัติการเข้าใช้งานด้วยครับ แยกเป็น 3 ไปเลย" --
             the plain "Employees" link (List + Recheck Data + Login History + Reports as 4 tabs on
             one page) is split into 3 standalone pages under this submenu: Employee (List + Recheck
             Data stay together as 2 tabs, both are per-employee data-management/validation views),
             Login History, and Reports (the 9-report sub-tab page from the 2026-09-02 Employee
             Reports phased plan -- now its own destination instead of a 4th top-level tab, same
             "Payslip & Documents" -> "Requests" submenu precedent already established below). Same
             .has-submenu/.submenu-toggle markup shape as the existing Reports submenu just below --
             public/js/app.js's own generic delegated handler needs zero new code for this to work.
             No permission gate existed on the old plain link either, so none is added here. -->
        <li class="menu-item has-submenu">
            <a href="javascript:void(0);" class="menu-link submenu-toggle">
                <span class="menu-icon">
                    <img src="<?=BASE_URL?>/public/images/menu/EMPLOYEE.SVG" alt="Employees">
                </span>
                <span class="menu-text" data-i18n="employees">Employees</span>
                <span class="menu-arrow"><i class="fas fa-chevron-down"></i></span>
            </a>
            <ul class="submenu">
                <li>
                    <a href="<?=BASE_URL?>/employees" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/EMPLOYEE.SVG" alt="Employee">
                        </span>
                        <span class="submenu-text" data-i18n="employee_list_menu">Employee List</span>
                    </a>
                </li>
                <li>
                    <a href="<?=BASE_URL?>/employees/login-history" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/EMPLOYEE.SVG" alt="Login History">
                        </span>
                        <span class="submenu-text" data-i18n="login_history">Login History</span>
                    </a>
                </li>
                <li>
                    <a href="<?=BASE_URL?>/employees/reports" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/REPORT.SVG" alt="Reports">
                        </span>
                        <span class="submenu-text" data-i18n="employee_reports">Reports</span>
                    </a>
                </li>
            </ul>
        </li>
        <!-- 2026-09-07, explicit request: "เมนูเยอะไปหมดตอนนี้...อย่างประมวลผลเงินเดือนกับอนุมัติเงินเดือน
             ถ้ารวมไปอยู่ใน Menu เดียวกันได้ก็ควรรวมครับ" -- Payroll Process and Payroll Approval merge
             into ONE top-level "Payroll" menu with a 2-item submenu. Routes/controllers/permissions
             are completely unchanged (still /payroll-process and /payroll-approval, same pages) --
             only the sidebar entry point consolidates. UserPreferenceModel::quickLinkCatalog()'s own
             `group` for both catalog keys was updated to match (see that method's own docblock on
             keeping this a hand-maintained mirror of the sidebar). -->
        <li class="menu-item has-submenu">
            <a href="javascript:void(0);" class="menu-link submenu-toggle">
                <span class="menu-icon">
                    <img src="<?=BASE_URL?>/public/images/menu/PAYROLL.SVG" alt="Payroll">
                </span>
                <span class="menu-text" data-i18n="payroll_menu">Payroll</span>
                <span class="menu-arrow"><i class="fas fa-chevron-down"></i></span>
            </a>
            <ul class="submenu">
                <li>
                    <a href="<?=BASE_URL?>/payroll-process" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/PAYROLL.SVG" alt="Payroll Process">
                        </span>
                        <span class="submenu-text" data-i18n="payroll_process">Payroll Process</span>
                    </a>
                </li>
                <li>
                    <a href="<?=BASE_URL?>/payroll-approval" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/APPROVAL.SVG" alt="Payroll Approval">
                        </span>
                        <span class="submenu-text" data-i18n="payroll_approval">Payroll Approval</span>
                    </a>
                </li>
            </ul>
        </li>
        <!-- 2026-08-23, explicit request ("เมนูช่วยเรียงลำดับเมนูตามความสำคัญให้ใหม่อีกครั้ง") -- Reports
             ranked above Payslip: statutory filings (ภ.ง.ด./สปส.) carry a hard monthly compliance
             deadline, while Payslip Requests/Settings is mostly automatic (auto-send mode) and only
             an on-demand convenience feature when it isn't -- higher stakes/more time-critical wins
             the higher slot. Core payroll-run flow (Process -> Approval) still comes first since
             that's the actual daily-driver; Time & Leave/Settings stay last as setup/admin surfaces
             touched far less often than any of the above. -->
        <!-- 2026-08-29, explicit request: "ต้องการอีกหน้าคล้ายๆหน้าของ Employee เป็นข้อมูลสรุปรอบตามปี...
             และส่วนของ Report ส่วนนี้ช่วยคิดให้หน่อยว่าควรเปิด Menu ใหม่ หรือเอาไปไว้ส่วนไหน" -- the new
             Annual Income Summary page is conceptually a report (per-employee income/deduction/net,
             just an interactive live table instead of a generate-and-download document like the
             rest of the Reports module), so it hangs off the SAME "Reports" concept rather than
             claiming its own top-level menu icon -- Reports becomes a submenu instead of a plain
             link. Permission (`annual_income_summary.view`) is gated at the controller, same as
             every other permission-gated page in this app -- the link itself is always shown, an
             unauthorized click lands on the shared permission-denied view.
             2026-09-07, explicit request: "เมนูเยอะไปหมดตอนนี้ ช่วย group รวม Menu ที่ควรอยู่ด้วยกัน" --
             the standalone "Audit Log" top-level entry (see its own retired comment further down)
             folds into this SAME submenu as a 4th item -- both are, at heart, "look at a history of
             what changed" tools (one payroll-run-scoped, one system-wide field-level), so they read
             naturally as one family under "Reports" instead of two separate top-level icons. Gate
             widened to `$canViewReportsMenu || $canViewAuditLogMenu` so the parent still shows for
             someone who can see ONLY Audit Log (e.g. no payroll_run.view/annual_income_summary.view
             grant at all) with nothing else in the submenu visible to them. -->
        <?php if ($canViewReportsMenu || $canViewAuditLogMenu): ?>
        <li class="menu-item has-submenu">
            <a href="javascript:void(0);" class="menu-link submenu-toggle">
                <span class="menu-icon">
                    <img src="<?=BASE_URL?>/public/images/menu/REPORT.SVG" alt="Reports">
                </span>
                <span class="menu-text" data-i18n="reports">Reports</span>
                <span class="menu-arrow"><i class="fas fa-chevron-down"></i></span>
            </a>
            <ul class="submenu">
                <?php if ($canViewReportsGenerateMenu): ?>
                <li>
                    <a href="<?=BASE_URL?>/reports" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/REPORT.SVG" alt="Generate Reports">
                        </span>
                        <span class="submenu-text" data-i18n="generate_reports">Generate Reports</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($canViewAnnualIncomeSummaryMenu): ?>
                <li>
                    <a href="<?=BASE_URL?>/reports/annual-summary" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/REPORT.SVG" alt="Annual Income Summary">
                        </span>
                        <span class="submenu-text" data-i18n="annual_income_summary">Annual Income Summary</span>
                    </a>
                </li>
                <?php endif; ?>
                <!-- 2026-08-31, same-day follow-up (item 10, explicit request: "Design ให้หน่อยครับ No
                     Idea" -- diff-history audit of every payroll run's manual edits). Same "interactive
                     page, not a generate-and-download document" reasoning as Annual Income Summary
                     above -- hangs off the same Reports submenu rather than a new top-level icon.
                     Gated by $canViewReportsGenerateMenu (payroll_run.view) since ReportsController::
                     runAudit() checks the same permission, not its own separate one. -->
                <?php if ($canViewReportsGenerateMenu): ?>
                <li>
                    <a href="<?=BASE_URL?>/reports/run-audit" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/REPORT.SVG" alt="Payroll Run Audit">
                        </span>
                        <span class="submenu-text" data-i18n="payroll_run_audit_menu">Payroll Run Audit</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($canViewAuditLogMenu): ?>
                <li>
                    <a href="<?=BASE_URL?>/audit-log" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/REPORT.SVG" alt="Audit Log">
                        </span>
                        <span class="submenu-text" data-i18n="audit_log_menu">Audit Log</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; ?>
        <!-- 2026-08-24, explicit request: "Menu Employment Ceritficate น่าจะนำไปรวมใน Play Slip แต่เปลี่ยน
             Menu ส่วนของการตั้งค่าก็เอาไปไว้ด้วยกัน แต่แยก Tab มีแค่ส่วนของการ Request ที่แยก Sub menu ย่อย" --
             the standalone Employment Certificate menu item (added earlier the same day) is now gone;
             its designer lives inside this menu's Settings as a 3rd tab (see payslip/settings.php).
             Its own route (/employment-certificate/settings) and every API endpoint still work
             unchanged -- only this menu entry point was removed. Requests stays separate below because
             Employment Certificate has no request/issuance flow yet (that's still a later phase, see
             CLAUDE.md).
             2026-08-24, later same day, explicit follow-up: "menu Payslip น่าจะต้องเปลี่ยนชื่อและ link
             นะครับ เพราะไม่ใช่แค่ payslip อย่างเดียว" -- label renamed to `payslip_menu` i18n value
             "Payslip & Documents"/"สลิป & เอกสาร" (same key, just a different value -- also picked up
             automatically by both sub-pages' own breadcrumb `bc-parent`, see payslip/requests.php and
             payslip/settings.php), and routes renamed payslip/* -> payslip-documents/* (see index.php's
             own comment on this same rename -- controller/view file paths are unaffected, internal
             only). -->
        <li class="menu-item has-submenu">
            <a href="javascript:void(0);" class="menu-link submenu-toggle">
                <span class="menu-icon">
                    <img src="<?=BASE_URL?>/public/images/menu/REPORT.SVG" alt="Payslip & Documents">
                </span>
                <span class="menu-text" data-i18n="payslip_menu">Payslip</span>
                <span class="menu-arrow"><i class="fas fa-chevron-down"></i></span>
            </a>
            <ul class="submenu">
                <li>
                    <a href="<?=BASE_URL?>/payslip-documents/requests" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/APPROVAL.SVG" alt="Requests">
                        </span>
                        <span class="submenu-text" data-i18n="requests">Requests</span>
                    </a>
                </li>
                <li>
                    <a href="<?=BASE_URL?>/payslip-documents/settings" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/SETTINGS.SVG" alt="Settings">
                        </span>
                        <span class="submenu-text" data-i18n="settings">Settings</span>
                    </a>
                </li>
            </ul>
        </li>
        <!-- Re-shown 2026-08-21 (explicit request: "เปิด Menu ที่ปิดไว้ขึ้นมาหน่อยครับ") -- previously
             hidden the same day ("เมนูเวลาทำงานและการลา ยังไม่ได้ใช้ใน phase นี้"), but its Setup & Rules
             submenu still owns Shift/Holiday/Leave Type/Work Location (OT Rate and the attendance
             deduction settings moved out to Payroll Configuration since then, not this menu itself)
             plus Manual Time Entry, both fully working underneath -- just re-enabling the entry point. -->
        <li class="menu-item">
            <a href="javascript:void(0);" class="menu-link submenu-toggle">
                <span class="menu-icon">
                    <img src="<?=BASE_URL?>/public/images/menu/TIME.SVG" alt="Settings">
                </span>
                <span class="menu-text" data-i18n="time_and_leave">Time & Leave</span>
                <span class="menu-arrow"><i class="fas fa-chevron-down"></i></span>
            </a>
            <ul class="submenu">
                <li>
                    <a href="<?=BASE_URL?>/setup-rules" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/Shift.SVG" alt="Setup & Rules">
                        </span>
                        <span class="submenu-text" data-i18n="setup_and_rules">Setup & Rules</span>
                    </a>
                </li>
                <li>
                    <a href="<?=BASE_URL?>/manual-entry" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/TIME.SVG" alt="Manual Time Entry">
                        </span>
                        <span class="submenu-text" data-i18n="manual_time_entry">Manual Time Entry</span>
                    </a>
                </li>
            </ul>
        </li>
        <!-- 2026-09-07, explicit request: "เมนูเยอะไปหมดตอนนี้ ช่วย group รวม Menu ที่ควรอยู่ด้วยกัน" --
             "Audit Log" (2026-09-03, Platform Hardening Phase 6 pilot) moved into the Reports
             submenu above -- see that submenu's own comment. "Announcements" (2026-09-04, Backlog
             Phase 10 T057) moved into the Settings submenu below -- both were single-purpose
             top-level entries that read more naturally as part of an existing group (a history/audit
             tool alongside Reports' own Payroll Run Audit; a company-wide broadcast CONFIGURATION
             tool alongside Settings' other admin config pages) than as their own icons in an
             already-long sidebar. Routes/controllers/permissions unchanged either way. -->
        <!-- 2026-09-06, explicit request: "ย้ายเมนูช่วยเหลือ มาไว้หลังตั้งค่า เมนูสิทธิ์การใช้งานมาไว้ภายใต้
             เมนูตั้งค่า" -- supersedes the 2026-08-30 "Settings is always last" rule right below (that
             comment is now historical/inaccurate -- Help moved to AFTER Settings, deliberately).
             Permissions (previously its own standalone top-level entry, see below) moved to become
             a submenu item WITHIN Settings instead. -->
        <li class="menu-item has-submenu">
            <a href="javascript:void(0);" class="menu-link submenu-toggle">
                <span class="menu-icon">
                    <img src="<?=BASE_URL?>/public/images/menu/SETTINGS.SVG" alt="Settings">
                </span>
                <span class="menu-text" data-i18n="settings">Settings</span>
                <span class="menu-arrow"><i class="fas fa-chevron-down"></i></span>
            </a>
            <ul class="submenu">
                <li>
                    <a href="<?=BASE_URL?>/setup/company-profile" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/COMPANY.SVG" alt="Company Profile">
                        </span>
                        <span class="submenu-text" data-i18n="company_profile">Company Profile</span>
                    </a>
                </li>
                <li>
                    <a href="<?=BASE_URL?>/setup/data-sync" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/ORIGAMI_APP.SVG" alt="Data Sync">
                        </span>
                        <span class="submenu-text" data-i18n="data_sync_menu">Data Sync</span>
                    </a>
                </li>
                <li>
                    <a href="<?=BASE_URL?>/setup/payroll-configuration" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/ORIGAMI_APP.SVG" alt="Payroll Configuration">
                        </span>
                        <span class="submenu-text" data-i18n="payroll_configuration">Payroll Configuration</span>
                    </a>
                </li>
                <li>
                    <a href="<?=BASE_URL?>/setup/tax-statutory" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/TAX.SVG" alt="Tax & Statutory">
                        </span>
                        <span class="submenu-text" data-i18n="tax_and_statutory">Tax & Statutory</span>
                    </a>
                </li>
                <?php if ($canViewApprovalWorkflowMenu): ?>
                <li>
                    <a href="<?=BASE_URL?>/setup/document-approval" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/APPROVAL.SVG" alt="Document & Approval">
                        </span>
                        <span class="submenu-text" data-i18n="document_and_approval">Document & Approval</span>
                    </a>
                </li>
                <?php endif; ?>
                <!-- 2026-09-06: moved here from its own standalone top-level entry (originally added
                     2026-08-31, "สิทธิ์การใช้งาน...อยากให้แยกออกมาเป็นอีก Menu ไปเลย") -- explicit
                     follow-up request now asks the opposite, folding it back under Settings. Route/
                     permission gate/icon all unchanged, only its position in the menu tree moved. -->
                <?php if ($canViewPermissionsMenu): ?>
                <li>
                    <a href="<?=BASE_URL?>/setup/permissions" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/APPROVAL.SVG" alt="Permissions">
                        </span>
                        <span class="submenu-text" data-i18n="permissions_menu">Permissions</span>
                    </a>
                </li>
                <?php endif; ?>
                <!-- 2026-09-07: moved here from its own standalone top-level entry (originally added
                     2026-09-04, Backlog Phase 10 T057) -- explicit request: "เมนูเยอะไปหมดตอนนี้ ช่วย
                     group รวม Menu ที่ควรอยู่ด้วยกัน" -- a company-wide broadcast CONFIGURATION tool
                     (`announcement.manage`) reads as one more admin setting alongside Company
                     Profile/Payroll Configuration/etc., same reasoning Permissions' own move here
                     already established. Route/permission gate/icon unchanged -- an employee's own
                     view of announcements they've received (Dashboard widget, Notifications, the
                     plain /announcements "my list" page) never went through this sidebar item at
                     all either way, see that permission's own header.php docblock. -->
                <?php if ($canViewAnnouncementMenu): ?>
                <li>
                    <a href="<?=BASE_URL?>/setup/announcements" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/APPROVAL.SVG" alt="Announcements">
                        </span>
                        <span class="submenu-text" data-i18n="announcement_menu">Announcements</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </li>
        <!-- 2026-09-06: moved here (was directly above Settings, per that section's own prior
             "any future top-level menu item goes ABOVE this one" rule) -- explicit request:
             "ย้ายเมนูช่วยเหลือ มาไว้หลังตั้งค่า" (move Help to AFTER Settings). Now the genuinely LAST
             top-level item -- any future item goes ABOVE Settings instead, not below Help. -->
        <li class="menu-item has-submenu">
            <a href="javascript:void(0);" class="menu-link submenu-toggle">
                <span class="menu-icon">
                    <img src="<?=BASE_URL?>/public/images/menu/REPORT.SVG" alt="Help">
                </span>
                <span class="menu-text" data-i18n="help_menu">Help</span>
                <span class="menu-arrow"><i class="fas fa-chevron-down"></i></span>
            </a>
            <ul class="submenu">
                <li>
                    <a href="<?=BASE_URL?>/help/setup-guide" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/REPORT.SVG" alt="Setup Guide">
                        </span>
                        <span class="submenu-text" data-i18n="setup_guide_menu">Setup Guide</span>
                    </a>
                </li>
                <li>
                    <a href="<?=BASE_URL?>/help/version" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/REPORT.SVG" alt="Version">
                        </span>
                        <span class="submenu-text" data-i18n="version_menu">Version</span>
                    </a>
                </li>
            </ul>
        </li>
    </ul>
</aside>