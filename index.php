<?php
    ini_set('session.cookie_httponly', 1);
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', 1);
    }
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
    require_once __DIR__ . '/vendor/autoload.php';
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    require_once __DIR__ . '/app/helpers/helpers.php';
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/app/core/Database.php';
    require_once __DIR__ . '/app/core/Controller.php';
    require_once __DIR__ . '/app/core/Router.php';
    spl_autoload_register(function ($class) {
        $paths = ['app/controllers/', 'app/models/', 'app/core/'];
        foreach ($paths as $path) {
            $file = __DIR__ . '/' . $path . $class . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    });
    $router = new Router();
    $router->get('/', 'DashboardController@index'); 
    $router->get('dashboard', 'DashboardController@index');
    $router->get('employees', 'EmployeeController@index');
    $router->get('/payroll-process', 'PayrollController@index');
    $router->get('setup/company', 'CompanyController@index');
    $router->get('setup/cycle', 'CycleController@index');
    $router->get('setup/earnings-deductions', 'EarningsDeductionsController@index');
    $router->get('setup/approval', 'ApprovalController@index');
    $router->get('setup/tax-statutory', 'TaxController@index');
    $router->get('setup/notification-setting', 'NotificationController@index');
    $router->get('reports', 'ReportsController@index');
    $router->get('submission', 'SubmissionController@index');
    $router->get('api/payroll/metadata', 'CompanyController@getMetadata');
    $router->post('api/payroll/save', 'CompanyController@save');
    $router->post('api/employee.list', 'EmployeeController@list');
    $router->get('/employees/create', 'EmployeeController@create');
    $router->get('/employees/{id}', 'EmployeeController@detail');
    $router->dispatch();