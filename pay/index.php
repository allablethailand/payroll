<?php
    session_start();
    $url = $_SERVER['REQUEST_URI'];
    switch($url) {
        case '/payroll/pay/':
        case '/payroll/pay':
            require __DIR__.'/views/pay.php';
        break;
        case '/payroll/pay/detail/':
        case '/payroll/pay/detail':
            require __DIR__.'/views/detail.php';
        break;
        default: 
            header('Location: /payroll/pay');
    }
?>