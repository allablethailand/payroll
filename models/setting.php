<?php
    session_start();
    $base_include = $_SERVER['DOCUMENT_ROOT'];
    $base_path = '';
    if($_SERVER['HTTP_HOST'] == 'localhost'){
        $request_uri = $_SERVER['REQUEST_URI'];
        $exl_path = explode('/',$request_uri);
        if(!file_exists($base_include."/dashboard.php")){
            $base_path .= "/".$exl_path[1];
        }
        $base_include .= "/".$exl_path[1];
    }
    define('BASE_PATH', $base_path);
    define('BASE_INCLUDE', $base_include);
    require_once $base_include.'/lib/connect_sqli.php';
    if(empty($_SESSION['comp_id']) || empty($_SESSION['emp_id'])) {
        echo json_encode([
            'status' => false,
            'message' => 'Connection Failed!'
        ]);
        exit;
    }
    if(isset($_POST['action']) && $_POST['action'] == 'loadPeriodList') {
        $table = "SELECT 
            p.period_id,
            p.status,
            p.period_name,
            p.cutoff_date,
            p.payment_date,
            CONCAT(IFNULL(i.firstname,i.firstname_th),' ',IFNULL(i.lastname,i.lastname_th)) AS emp_name,
            date_format(p.date_modify, '%Y/%m/%d %H:%i:%s') as date_modify
        FROM 
            payroll_period p
        LEFT JOIN 
            m_employee_info i on i.emp_id = p.emp_modify
        WHERE 
            p.comp_id = '{$_SESSION['comp_id']}' and p.status <> 2";
        $primaryKey = 'period_id';
        $columns = array(
            array('db' => 'period_id', 'dt' => 'period_id'),
            array('db' => 'status', 'dt' => 'status'),
            array('db' => 'period_name', 'dt' => 'period_name'),
            array('db' => 'cutoff_date', 'dt' => 'cutoff_date'),
            array('db' => 'payment_date', 'dt' => 'payment_date'),
            array('db' => 'emp_name', 'dt' => 'emp_name'),
            array('db' => 'date_modify', 'dt' => 'date_modify'),
        );
        $sql_details = array('user' => $db_username,'pass' => $db_pass_word,'db'   => $db_name,'host' => $db_host);
		require($base_include.'/lib/ssp-subquery.class.php');
		echo json_encode(SSP::simple($_POST, $sql_details, $table, $primaryKey, $columns));
		exit();
    }
    if(isset($_POST['action']) && $_POST['action'] == 'periodData') {
        $period_id = isset($_POST['period_id']) ? $_POST['period_id'] : '';
        if(!$period_id) {
            echo json_encode([
                'status' => false,
                'message' => 'Period Item Not Found.'
            ]);
            exit;
        }
        $period = select_data(
            "period_name, cutoff_date, payment_date", "payroll_period", "where period_id = '{$period_id}'"
        );
        if(!isset($period)) {
            echo json_encode([
                'status' => false,
                'message' => 'Period Data Not Found.'
            ]);
            exit;
        }
        $p = $period[0];
        echo json_encode([
            'status' => true, 
            'period_data' => [
                'period_name' => $p['period_name'],
                'cutoff_date' => $p['cutoff_date'],
                'payment_date' => $p['payment_date']
            ]
        ]);
    }
    if ($_POST['action'] === 'savePeriod') {
        $period_id    = $_POST['period_id'];
        $period_name  = escape_string(trim($_POST['period_name']));
        $cutoff_date  = intval($_POST['cutoff_date']);
        $payment_date = intval($_POST['payment_date']);
        if (!$period_name) {
            echo json_encode(['status' => false, 'message' => 'Period name is required.']);
            exit;
        }
        if ($period_id) {
            update_data(
                "payroll_period",
                "period_name = '{$period_name}', cutoff_date = '{$cutoff_date}', payment_date = '{$payment_date}', emp_modify = '{$_SESSION['emp_id']}', date_modify = NOW()",
                "period_id = '{$period_id}'"
            );
            $msg = 'Period updated successfully.';
        } else {
            insert_data(
                "payroll_period",
                "(period_name, cutoff_date, payment_date, status, comp_id, emp_create, date_create, emp_modify, date_modify)",
                "('{$period_name}', '{$cutoff_date}', '{$payment_date}', 1, '{$_SESSION['comp_id']}','{$_SESSION['emp_id']}', NOW(), '{$_SESSION['emp_id']}', NOW())"
            );
            $msg = 'Period created successfully.';
        }
        echo json_encode(['status' => true, 'message' => $msg]);
        exit;
    }
    if(isset($_POST['action']) && $_POST['action'] == 'delPeriod') {
        $period_id = isset($_POST['period_id']) ? $_POST['period_id'] : '';
        if(!$period_id) {
            echo json_encode([
                'status' => false,
                'message' => 'Period Item Not Found.'
            ]);
            exit;
        }
        update_data(
            "payroll_period", "status = 2, emp_modify = '{$_SESSION['emp_id']}', date_modify = NOW()", "period_id = '{$period_id}'"
        );
        echo json_encode([
            'status' => true,
        ]);
    }
    if(isset($_POST['action']) && $_POST['action'] == 'switchPeriod') {
        $period_id = isset($_POST['period_id']) ? $_POST['period_id'] : '';
        $option = isset($_POST['option']) ? $_POST['option'] : '';
        if(!$period_id || !$option) {
            echo json_encode([
                'status' => false,
                'message' => 'Period Item Not Found.'
            ]);
            exit;
        }
        $status = ($option == 'off') ? 1 : 0;
        update_data(
            "payroll_period", "status = $status, emp_modify = '{$_SESSION['emp_id']}', date_modify = NOW()", "period_id = '{$period_id}'"
        );
        echo json_encode([
            'status' => true,
        ]);
    }
    if(isset($_POST['action']) && $_POST['action'] == 'loadPayrollItem'){
        $type = $_POST['item_type'];
        $table = "SELECT 
                i.item_id,
                i.item_name_en,
                i.item_name_th,
                i.status,
                DATE_FORMAT(i.date_modify,'%Y/%m/%d %H:%i:%s') date_modify,
                CONCAT(IFNULL(e.firstname,e.firstname_th),' ',IFNULL(e.lastname,e.lastname_th)) emp_name,
                'master' as item_key
            FROM payroll_item_master i
            LEFT JOIN m_employee_info e ON e.emp_id = i.emp_modify
            WHERE i.item_type = '{$type}' AND i.status <> 2
            UNION 
            SELECT 
                i.item_id,
                i.item_name_en,
                i.item_name_th,
                i.status,
                DATE_FORMAT(i.date_modify,'%Y/%m/%d %H:%i:%s') date_modify,
                CONCAT(IFNULL(e.firstname,e.firstname_th),' ',IFNULL(e.lastname,e.lastname_th)) emp_name,
                'company' as item_key
            FROM 
                payroll_item_comp i
            LEFT JOIN m_employee_info e ON e.emp_id = i.emp_modify
            WHERE i.item_type = '{$type}' AND i.status <> 2  and i.comp_id = '{$_SESSION['comp_id']}'
        ";
        $primaryKey = 'item_id';
        $columns = [
            ['db'=>'item_id','dt'=>'item_id'],
            ['db'=>'status','dt'=>'status'],
            ['db'=>'item_name_en','dt'=>'item_name_en'],
            ['db'=>'item_name_th','dt'=>'item_name_th'],
            ['db'=>'date_modify','dt'=>'date_modify'],
            ['db'=>'emp_name','dt'=>'emp_name'],
            ['db'=>'item_key','dt'=>'item_key'],
        ];
        $sql_details = array('user' => $db_username,'pass' => $db_pass_word,'db'   => $db_name,'host' => $db_host);
        require($base_include.'/lib/ssp-subquery.class.php');
        echo json_encode(SSP::simple($_POST, $sql_details, $table, $primaryKey, $columns));
        exit();
    }
    if(isset($_POST['action']) && $_POST['action'] == 'loadPayrollItem'){
        $type = $_POST['item_type'];
        $table = "SELECT 
                i.item_id,
                i.item_name_en,
                i.item_name_th,
                i.status,
                DATE_FORMAT(i.date_modify,'%Y/%m/%d %H:%i:%s') date_modify,
                CONCAT(IFNULL(e.firstname,e.firstname_th),' ',IFNULL(e.lastname,e.lastname_th)) emp_name,
                'master' as item_key
            FROM payroll_item_master i
            LEFT JOIN m_employee_info e ON e.emp_id = i.emp_modify
            WHERE i.item_type = '{$type}' AND i.status <> 2 
            UNION 
            SELECT 
                i.item_id,
                i.item_name_en,
                i.item_name_th,
                i.status,
                DATE_FORMAT(i.date_modify,'%Y/%m/%d %H:%i:%s') date_modify,
                CONCAT(IFNULL(e.firstname,e.firstname_th),' ',IFNULL(e.lastname,e.lastname_th)) emp_name,
                'company' as item_key
            FROM 
                payroll_item_comp i
            LEFT JOIN m_employee_info e ON e.emp_id = i.emp_modify
            WHERE i.item_type = '{$type}' AND i.status <> 2  and i.comp_id = '{$_SESSION['comp_id']}'
        ";
        $primaryKey = 'item_id';
        $columns = [
            ['db'=>'item_id','dt'=>'item_id'],
            ['db'=>'status','dt'=>'status'],
            ['db'=>'item_name_en','dt'=>'item_name_en'],
            ['db'=>'item_name_th','dt'=>'item_name_th'],
            ['db'=>'date_modify','dt'=>'date_modify'],
            ['db'=>'emp_name','dt'=>'emp_name'],
            ['db'=>'item_key','dt'=>'item_key'],
        ];
        $sql_details = array('user' => $db_username,'pass' => $db_pass_word,'db'   => $db_name,'host' => $db_host);
        require($base_include.'/lib/ssp-subquery.class.php');
        echo json_encode(SSP::simple($_POST, $sql_details, $table, $primaryKey, $columns));
        exit();
    }
    if(isset($_POST['action']) && $_POST['action'] == 'savePayrollItem'){
        $id = $_POST['item_id'];
        $en = escape_string($_POST['item_name_en']);
        $th = escape_string($_POST['item_name_th']);
        $description = escape_string($_POST['description']);
        $type = $_POST['item_type'];
        if($id) {
            update_data(
                'payroll_item_comp',
                "item_name_en = '{$en}', item_name_th = '{$th}', description = '{$description}', emp_modify = '{$_SESSION['emp_id']}', date_modify = NOW()",
                "item_id='{$id}'"
            );
            $msg = 'Updated successfully';
        } else {
            insert_data(
                'payroll_item_comp',
                '(item_name_en,item_name_th,description,item_type,status,emp_create,date_create,emp_modify,date_modifyม comp_id)',
                "('{$en}','{$th}','{$description}','{$type}',1,'{$_SESSION['emp_id']}',NOW(),'{$_SESSION['emp_id']}',NOW(),'{$_SESSION['comp_id']}')"
            );
            $msg = 'Created successfully';
        }
        echo json_encode(['status'=>true,'message'=>$msg]);
        exit;
    }
    if(isset($_POST['action']) && $_POST['action'] == 'delPayrollItem'){
        update_data(
            'payroll_item_comp',
            "status = 2, emp_modify = '{$_SESSION['emp_id']}', date_modify = NOW()",
            "item_id = '{$_POST['item_id']}'"
        );
        echo json_encode(['status'=>true]); exit;
    }
    if(isset($_POST['action']) && $_POST['action'] == 'switchPayrollItem'){
        $item_key = $_POST['item_key'];
        $status = ($_POST['option'] == 'off') ? 0 : 1;
        if($item_key == 'company') {
            update_data(
                'payroll_item_comp',
                "status = '{$status}', emp_modify = '{$_SESSION['emp_id']}', date_modify = NOW()",
                "item_id = '{$_POST['item_id']}'"
            );
        } else {
            $exits = select_data(
                "close_id", "payroll_item_comp_close", "where item_id = '{$_POST['item_id']}' and comp_id = '{$_SESSION['comp_id']}'"
            );
            if(!empty($exits)) {
                update_data(
                    "payroll_item_comp_close", 
                    "status = '{$status}', emp_modify = '{$_SESSION['emp_id']}', date_modify = NOW()", 
                    "item_id = '{$_POST['item_id']}' and comp_id = '{$_SESSION['comp_id']}'"
                );
            } else {
                insert_data(
                    "payroll_item_comp_close", 
                    "(item_id, comp_id, status, emp_create, date_create, emp_modify, date_modify)",
                    "('{$_POST['item_id']}', '{$_SESSION['comp_id']}', '{$status}', '{$_SESSION['emp_id']}', NOW(), '{$_SESSION['emp_id']}', NOW())"
                );
            }
        }
        echo json_encode(['status'=>true]); exit;
    }
    if(isset($_POST['action']) && $_POST['action'] == 'payrollItemData') {
        $item_id = $_POST['item_id'];
        $item = select_data(
            "item_name_en, item_name_th, description", "payroll_item_comp", "where item_id = '{$item_id}'"
        );
        echo json_encode([
            'status' => true,
            'item_name_en' => $item[0]['item_name_en'],
            'item_name_th' => $item[0]['item_name_th'],
            'description' => $item[0]['description'],
        ]);
    }
?>