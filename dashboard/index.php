<?php
    session_start();
    $url = $_SERVER['REQUEST_URI'];
    switch($url) {
        case '/payroll/dashboard/':
        case '/payroll/dashboard':
            require __DIR__.'/views/dashboard.php';
        break;
        default: 
            header('Location: /payroll/dashboard');
    }
?>