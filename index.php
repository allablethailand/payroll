<?php
    session_start();
    $url = $_SERVER['REQUEST_URI'];
    switch($url) {
        case '/payroll/':
        case '/payroll':
            require __DIR__.'/views/payroll.php';
        break;
        case '/payroll/setting':
            require __DIR__.'/views/setting.php';
        break;
        default: 
            $_SESSION['page'] = 'home';
            header('Location: /payroll');
    }
?>