<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payroll • ORIGAMI PLATFORM</title>
<link rel="icon" type="image/png" href="<?=BASE_URL?>/public/images/logo_vertical.png">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
<link href="<?=BASE_URL?>/node_modules/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="<?=BASE_URL?>/node_modules/@fortawesome/fontawesome-free/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?=BASE_URL?>/node_modules/sweetalert2/dist/sweetalert2.min.css">
<link rel="stylesheet" href="<?=BASE_URL?>/node_modules/datatables.net-bs5/css/dataTables.bootstrap5.min.css">
<link href="<?=BASE_URL?>/node_modules/select2/dist/css/select2.min.css" rel="stylesheet">
<link href="<?=BASE_URL?>/node_modules/select2-bootstrap-5-theme/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?=BASE_URL?>/node_modules/bootstrap-datepicker/dist/css/bootstrap-datepicker.standalone.min.css">
<link rel="stylesheet" href="<?=BASE_URL?>/node_modules/intl-tel-input/dist/css/intlTelInput.min.css">
<!-- 2026-08-26, explicit request: "ที่อยู่ให้เพิ่มสามารถปักหมุด Location บนแผนที่ได้" -- OpenStreetMap +
     Leaflet (chosen over Google Maps: free, no API key needed), same node_modules-served convention as
     every other JS dependency in this project. -->
<link rel="stylesheet" href="<?=BASE_URL?>/node_modules/leaflet/dist/leaflet.css">
<link rel="stylesheet" href="<?=asset('public/css/style.css')?>">
<script>
    const BASE_URL = "<?=BASE_URL?>";
</script>
</head>
<body>
<script src="<?=BASE_URL?>/node_modules/jquery/dist/jquery.min.js"></script>
<script src="<?=asset('public/js/app.js')?>"></script>
<script src="<?=asset('public/js/alert.js')?>"></script>
<script src="<?=asset('public/js/input.js')?>"></script>
<nav class="origami-navbar">
    <div class="nav-container">
        <div class="nav-left">
            <button class="nav-btn-hamberger" type="button">
                <img src="<?=BASE_URL?>/public/images/HambergerIcon.svg" alt="Menu">
            </button>
            <a class="nav-logo" href="<?=BASE_URL?>/dashboard">
                <img src="<?=BASE_URL?>/public/images/logo_horizontal.png" alt="Origami Logo">
            </a>
        </div>
        <div class="nav-right">
            <a href="#" class="nav-icon-link">
                <img src="<?=BASE_URL?>/public/images/Bell.svg" alt="Notifications">
            </a>
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
            <a href="#" class="nav-profile-link">
                <div class="profile-img-box">
                    <img src="<?=BASE_URL?>/public/images/userNoImage.jpg" alt="User Profile">
                </div>
            </a>
        </div>
    </div>
</nav>
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
        <li class="menu-item">
            <a href="<?=BASE_URL?>/employees" class="menu-link">
                <span class="menu-icon">
                    <img src="<?=BASE_URL?>/public/images/menu/EMPLOYEE.SVG" alt="Employees">
                </span>
                <span class="menu-text" data-i18n="employees">Employees</span>
            </a>
        </li>
        <li class="menu-item">
            <a href="<?=BASE_URL?>/payroll-process" class="menu-link">
                <span class="menu-icon">
                    <img src="<?=BASE_URL?>/public/images/menu/PAYROLL.SVG" alt="Payroll Process">
                </span>
                <span class="menu-text" data-i18n="payroll_process">Payroll Process</span>
            </a>
        </li>
        <li class="menu-item">
            <a href="<?=BASE_URL?>/payroll-approval" class="menu-link">
                <span class="menu-icon">
                    <img src="<?=BASE_URL?>/public/images/menu/APPROVAL.SVG" alt="Payroll Approval">
                </span>
                <span class="menu-text" data-i18n="payroll_approval">Payroll Approval</span>
            </a>
        </li>
        <!-- 2026-08-23, explicit request ("เมนูช่วยเรียงลำดับเมนูตามความสำคัญให้ใหม่อีกครั้ง") -- Reports
             ranked above Payslip: statutory filings (ภ.ง.ด./สปส.) carry a hard monthly compliance
             deadline, while Payslip Requests/Settings is mostly automatic (auto-send mode) and only
             an on-demand convenience feature when it isn't -- higher stakes/more time-critical wins
             the higher slot. Core payroll-run flow (Process -> Approval) still comes first since
             that's the actual daily-driver; Time & Leave/Settings stay last as setup/admin surfaces
             touched far less often than any of the above. -->
        <li class="menu-item">
            <a href="<?=BASE_URL?>/reports" class="menu-link">
                <span class="menu-icon">
                    <img src="<?=BASE_URL?>/public/images/menu/REPORT.SVG" alt="Reports">
                </span>
                <span class="menu-text" data-i18n="reports">Reports</span>
            </a>
        </li>
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
                <li>
                    <a href="<?=BASE_URL?>/setup/document-approval" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/APPROVAL.SVG" alt="Document & Approval">
                        </span>
                        <span class="submenu-text" data-i18n="document_and_approval">Document & Approval</span>
                    </a>
                </li>
            </ul>
        </li>
    </ul>
</aside>