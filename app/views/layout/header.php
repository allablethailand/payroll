<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payroll • ORIGAMI PLATFORM</title>
<link rel="icon" type="image/png" href="<?=BASE_URL?>/public/images/logo_vertical.png">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
<link href="<?=BASE_URL?>/node_modules/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="<?=BASE_URL?>/node_modules/fontawesome-free-7.1.0-web/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?=BASE_URL?>/node_modules/sweetalert2/dist/sweetalert2.min.css">
<link rel="stylesheet" href="<?=asset('public/css/style.css')?>">
<script>
    const BASE_URL = "<?=BASE_URL?>";
</script>
</head>
<body class="bg-light">
<script src="<?=BASE_URL?>/node_modules/jquery/dist/jquery.min.js"></script>
<script src="<?=asset('public/js/app.js')?>"></script>
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
            <a href="#" class="nav-icon-link">
                <img src="<?=BASE_URL?>/public/images/HUB.svg" alt="Origami Hub">
            </a>
            <div class="nav-lang-dropdown">
                <button class="nav-lang-btn" type="button">
                    <img class="current-flag" src="<?=BASE_URL?>/public/flags/gb.png" width="15" alt="EN flag">
                    <span class="lang-text text-current-lang">EN</span>
                </button>
                <ul class="nav-lang-menu" id="languageMenu">
                    <li>
                        <a href="javascript:void(0)" class="dropdown-lang-item" data-value="en" data-lang="EN" data-flag="<?=BASE_URL?>/public/flags/gb.png">
                            <img src="<?=BASE_URL?>/public/flags/gb.png" width="15" alt="EN"> English
                        </a>
                    </li>
                    <li>
                        <a href="javascript:void(0)" class="dropdown-lang-item" data-value="th" data-lang="TH" data-flag="<?=BASE_URL?>/public/flags/th.png">
                            <img src="<?=BASE_URL?>/public/flags/th.png" width="15" alt="TH"> ไทย
                        </a>
                    </li>
                </ul>
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
            <a href="<?=BASE_URL?>/timesheets" class="menu-link">
                <span class="menu-icon">
                    <img src="<?=BASE_URL?>/public/images/menu/TIMESHEET.SVG" alt="Time Sheets">
                </span>
                <span class="menu-text" data-i18n="timesheets">Time Sheets</span>
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
            <a href="<?=BASE_URL?>/reports" class="menu-link">
                <span class="menu-icon">
                    <img src="<?=BASE_URL?>/public/images/menu/REPORT.SVG" alt="Reports">
                </span>
                <span class="menu-text" data-i18n="reports">Reports</span>
            </a>
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
                    <a href="<?=BASE_URL?>/setup/company" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/COMPANY.SVG" alt="Company Setup">
                        </span>
                        <span class="submenu-text" data-i18n="company_setup">Company Setup</span>
                    </a>
                </li>
                <li>
                    <a href="<?=BASE_URL?>/setup/cycle" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/ORIGAMI_APP.SVG" alt="Payroll Cycle">
                        </span>
                        <span class="submenu-text" data-i18n="payroll_cycle">Payroll Cycle</span>
                    </a>
                </li>
                <li>
                    <a href="<?=BASE_URL?>/setup/earnings-deductions" class="submenu-link">
                        <span class="submenu-icon">
                            <img src="<?=BASE_URL?>/public/images/menu/ORIGAMI_APP.SVG" alt="Earnings / Deductions">
                        </span>
                        <span class="submenu-text" data-i18n="earnings_deductions">Earnings / Deductions</span>
                    </a>
                </li>
            </ul>
        </li>
    </ul>
</aside>