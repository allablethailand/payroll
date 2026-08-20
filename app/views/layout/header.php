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
                                <a href="<?=htmlspecialchars(rtrim(ORIGAMI_BASE_URL, '/'), ENT_QUOTES, 'UTF-8')?>/api/oauth/v2/switch?token=<?=urlencode($switchToken)?>&app=<?=urlencode((string)($app['app_key'] ?? ''))?>">
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
    <ul class="sidebar-menu">
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
        <li class="menu-item">
            <a href="<?=BASE_URL?>/reports" class="menu-link">
                <span class="menu-icon">
                    <img src="<?=BASE_URL?>/public/images/menu/REPORT.SVG" alt="Reports">
                </span>
                <span class="menu-text" data-i18n="reports">Reports</span>
            </a>
        </li>
        <!-- Hidden 2026-08-21 (explicit request: "เมนูเวลาทำงานและการลา ยังไม่ได้ใช้ใน phase นี้") --
             Setup & Rules/Manual Time Entry aren't part of this phase yet. Kept in the DOM (d-none),
             not deleted -- both pages/routes/controllers underneath are untouched and fully working,
             this only hides the sidebar entry point. -->
        <li class="menu-item d-none">
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