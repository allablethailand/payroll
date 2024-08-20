<?php
    session_start();
    $url = $_SERVER['REQUEST_URI'];
    switch($url) {
        case '/payroll/setting/':
        case '/payroll/setting':
            require __DIR__.'/views/setting.php';
        break;
        default: 
            header('Location: /payroll/setting');
    }
?>