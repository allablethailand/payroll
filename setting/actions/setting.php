<?php   
    session_start();
    $base_include = $_SERVER['DOCUMENT_ROOT'];
    $base_path = '';
    if($_SERVER['HTTP_HOST'] == 'localhost') {
        $request_uri = $_SERVER['REQUEST_URI'];
        $exl_path = explode('/',$request_uri);
        if(!file_exists($base_include."/dashboard.php")){
            $base_path .= "/".$exl_path[1];
        }
        $base_include .= "/".$exl_path[1];
    }
    DEFINE('base_path', $base_path);
    DEFINE('base_include', $base_include);
    require_once $base_include.'/lib/connect_sqli.php';
    if($_POST['action'] == 'buildPermission') {
        $table = "SELECT 
                payroll_permission.permission_id,
                payroll_permission.special_permission,
                CONCAT(IFNULL(i.firstname,i.firstname_th),' ',IFNULL(i.lastname,i.lastname_th)) AS emp_name,
                payroll_permission.emp_id
            FROM 
                payroll_permission
            LEFT JOIN 
                m_employee_info i on i.emp_id = payroll_permission.emp_id
            WHERE 
                payroll_permission.comp_id = '{$_SESSION['comp_id']}' and payroll_permission.status = 0 
            GROUP BY 
                payroll_permission.permission_id";
        $primaryKey = 'permission_id';
        $columns = array(
            array('db' => 'permission_id', 'dt' => 'permission_id'),
            array('db' => 'special_permission', 'dt' => 'special_permission'),
            array('db' => 'emp_id', 'dt' => 'emp_id'),
            array('db' => 'emp_name', 'dt' => 'emp_name')
        );
        $sql_details = array('user' => $db_username,'pass' => $db_pass_word,'db'   => $db_name,'host' => $db_host);
        require($base_include.'/lib/ssp-subquery.class.php');
        echo json_encode(SSP::simple($_POST, $sql_details, $table, $primaryKey, $columns));
		exit();
    }
    if($_GET['action'] == 'buildEmployee') {
		$keyword = trim($_GET['term']);
		$search = ($keyword) ? " and (i.firstname like '%{$keyword}%' or i.firstname_th like '%{$keyword}%' or i.lastname like '%{$keyword}%' or i.lastname_th like '%{$keyword}%') " : "";
		$resultCount = 10;
		$end = ($_GET['page'] - 1) * $resultCount;
		$start = $end + $resultCount;
		$columnData = "*";
        $tableData = "(select distinct i.emp_id as emp_code,CONCAT(IFNULL(i.firstname,i.firstname_th),' ',IFNULL(i.lastname,i.lastname_th)) AS emp_name from m_employee_info i left join m_employee emp on i.emp_id = emp.emp_id  where emp.comp_id = '{$_SESSION['comp_id']}' and emp.emp_del is null $search and date(ifnull(emp.emp_end_date,NOW())) >= date(NOW()) and emp.system_type = 1) data_table";
		$whereData = (($_GET['page']) ? "LIMIT ".$end.",".$start : "")."";
		$Data = select_data($columnData,$tableData,$whereData);
		$count_data = count($Data);
		$i = 0;
		while($i < $count_data) {
			$data[] = ['id' => $Data[$i]['emp_code'],'col' => $Data[$i]['emp_name'],'total_count' => $count_data,'code' => $Data[$i]['emp_code'],'desc' => $Data[$i]['emp_name'],];
			++$i;
		}
		if(empty($data)) {
			$empty[] = ['id' => '','col' => '', 'total_count' => ''];
			echo json_encode($empty);
		} else {
			echo json_encode($data);
		}
    }
    if($_GET['action'] == 'savePermission') {
        $permission_row = $_POST['permission_row'];
        $tableUpdData = "payroll_permission";
        $valueUpdData = "status = 1,emp_modify = '{$_SESSION['emp_id']}',date_modify = NOW()";
        $whereUpdData = "comp_id = '{$_SESSION['comp_id']}' and status = 0";
        update_data($tableUpdData,$valueUpdData,$whereUpdData);
        $i = 0;
        while($i < count($permission_row)) {
            $permission_id = $_POST['permission_id'.$i];
            $emp_id = $_POST['emp_id'.$i];
            $special_permission = $_POST['special_permission'.$i];
            $permission = (isset($special_permission)) ? $special_permission : 'N';
            if($permission_id) {
                $tableUpdData = "payroll_permission";
                $valueUpdData = "emp_id = '{$emp_id}',special_permission = '{$permission}',status = 0,emp_modify = '{$_SESSION['emp_id']}',date_modify = NOW()";
                $whereUpdData = "comp_id = '{$_SESSION['comp_id']}' and permission_id = '{$permission_id}'";
                update_data($tableUpdData,$valueUpdData,$whereUpdData);
            } else {
                $tableInsData = "payroll_permission";
                $columnInsData = "(
                    comp_id,
                    emp_id,
                    special_permission,
                    status,
                    emp_create,
                    date_create,
                    emp_modify,
                    date_modify
                )";
                $valueInsData = "(
                    '{$_SESSION['comp_id']}',
                    '{$emp_id}',
                    '{$permission}',
                    0,
                    '{$_SESSION['emp_id']}',
                    NOW(),
                    '{$_SESSION['emp_id']}',
                    NOW()
                )";
                insert_data($tableInsData,$columnInsData,$valueInsData);
            }
            ++$i;
        }
        echo json_encode(['status' => true]);
    }
?>