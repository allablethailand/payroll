<?php
    session_start();
    $url = $_SERVER['REQUEST_URI'];
    switch($url) {
        case '/payroll/employee/':
        case '/payroll/employee':
            require __DIR__.'/views/employee.php';
        break;
        default: 
            header('Location: /payroll/employee');
    }
?>