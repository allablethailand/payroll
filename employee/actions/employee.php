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
    require_once $base_include.'/actions/func.php';
    $fsData = getBucketMaster();
	$filesystem_user = $fsData['fs_access_user'];
	$filesystem_pass = $fsData['fs_access_pass'];
	$filesystem_host = $fsData['fs_host'];
	$filesystem_path = $fsData['fs_access_path'];
	$filesystem_type = $fsData['fs_type'];
	$fs_id = $fsData['fs_id'];
	setBucket($fsData);
    if($_POST['action'] == 'buildEmployee') {
        $table = "SELECT 
                    emp.emp_id,
                    emp.emp_code,
                    dept.dept_description,
                    pos.posi_description,
                    ac_role.acrp_name,
                    emp_info.nickname,
					emp_info.emp_pic,
					concat(ifnull(emp_info.firstname,emp_info.firstname_th),' ',ifnull(emp_info.lastname,emp_info.lastname_th)) as emp_name,
					concat(concat(concat(emp_info.firstname,' ',emp_info.firstname_th),' ',emp_info.lastname),' ',emp_info.lastname_th) as emp_key_name,
                    emp_type.emp_type_name,
                    emp_status.status_name,
                    date_format(emp.emp_start_date,'%Y/%m/%d') as emp_start_date
                FROM
                    m_employee emp 
                LEFT JOIN 
                    m_employee_info emp_info on emp_info.emp_id = emp.emp_id
                LEFT JOIN 
                    m_department dept on dept.dept_id = emp.dept_id
                LEFT JOIN 
                    m_position pos on pos.posi_id = emp.posi_id
                LEFT JOIN 
					ac_role_position ac_role on ac_role.acrp_id = emp.acrp_id
                LEFT JOIN 
					m_employee_salary on m_employee_salary.emp_id = emp.emp_id
                LEFT JOIN 
					m_employee_type emp_type on emp_type.emp_type_id = m_employee_salary.emp_type_id
                LEFT JOIN  
					m_employee_status emp_status on emp_status.status_id = emp_info.emp_status_id
                WHERE 
                    emp.comp_id = '{$_SESSION['comp_id']}' and emp_del is null and date(ifnull(emp.emp_end_date,NOW())) >= date(NOW()) and emp.system_type = 1";
        $primaryKey = 'emp_id';
        $columns = array(
            array('db' => 'emp_id', 'dt' => 'emp_id'),
            array('db' => 'emp_code', 'dt' => 'emp_code'),
            array('db' => 'emp_name', 'dt' => 'emp_name'),
            array('db' => 'emp_key_name', 'dt' => 'emp_key_name'),
            array('db' => 'nickname', 'dt' => 'nickname'),
            array('db' => 'acrp_name', 'dt' => 'acrp_name'),
            array('db' => 'emp_type_name', 'dt' => 'emp_type_name'),
            array('db' => 'dept_description', 'dt' => 'dept_description'),
            array('db' => 'posi_description', 'dt' => 'posi_description'),
            array('db' => 'status_name', 'dt' => 'status_name'),
            array('db' => 'emp_start_date', 'dt' => 'emp_start_date'),
            array('db' => 'emp_pic', 'dt' => 'emp_pic','formatter' => function ($d, $row) {
                $img = (file_exists('../../../'.$d)) ? '/'.$d : GetUrl($d);
                return $img; 
			}),
        );
        $sql_details = array('user' => $db_username,'pass' => $db_pass_word,'db'   => $db_name,'host' => $db_host);
		require($base_include.'/lib/ssp-subquery.class.php');
		echo json_encode(SSP::simple($_POST, $sql_details, $table, $primaryKey, $columns));
		exit();
    }
    if($_POST['action'] == 'memberData') {
        $emp_id = $_POST['emp_id'];
        $columnData = "emp_info.nickname,
					concat(ifnull(emp_info.firstname,emp_info.firstname_th),' ',ifnull(emp_info.lastname,emp_info.lastname_th)) as emp_name,
                    emp.emp_code";
        $tableData = "m_employee emp";
        $whereData = "LEFT JOIN 
                        m_employee_info emp_info on emp_info.emp_id = emp.emp_id 
                    WHERE 
                        emp.emp_id = '{$emp_id}'";
        $Data = select_data($columnData,$tableData,$whereData);
        echo json_encode([
            'status' => true,
            'emp_data' => $Data[0]
        ]);
    }
    if($_POST['action'] == 'buildEmpSalary') {
        $emp_id = $_POST['emp_id'];
        $columnData = "emp.emp_payment_method as pay_type,
                        m_bank.bank_id,
                        ifnull(m_bank.bank_name_en,m_bank.bank_name) as bank_name,
                        emp.emp_bank_account_no as bank_no,
                        emp.emp_salary_value as salary_val";
        $tableData = "payroll_emp_salary emp";
        $whereData = "left join 
                            m_bank on m_bank.bank_id = emp.emp_bank_account 
                        where 
                            emp.emp_id = '{$emp_id}'";
        $Data = select_data($columnData,$tableData,$whereData);
        $count_data = count($Data);
        if($count_data == 0) {
            $columnData = "emp_salary.pay_type,
                        m_bank.bank_id,
                        ifnull(m_bank.bank_name_en,m_bank.bank_name) as bank_name,
                        emp_salary.pay_bank_no as bank_no,
                        0 as salary_val";
            $tableData = "m_employee_salary emp_salary";
            $whereData = "left join 
                            m_bank on m_bank.bank_id = emp_salary.pay_bank
                        where 
                            emp_salary.emp_id = '{$emp_id}'";
            $Data = select_data($columnData,$tableData,$whereData);
        }
        echo json_encode([
            'status' => true,
            'emp_data' => $Data[0]
        ]);
    }
    if($_GET['action'] == 'buildBank') {
		$keyword = trim($_GET['term']);
		$search = ($keyword) ? " where (bank_name_en like '%{$keyword}%' or bank_name like '%{$keyword}%') " : "";
		$resultCount = 10;
		$end = ($_GET['page'] - 1) * $resultCount;
		$start = $end + $resultCount;
		$columnData = "*";
        $tableData = "(
                        select 
                            bank_id as data_code,
                            ifnull(bank_name_en,bank_name) as date_desc 
                        from 
                            m_bank 
                        $search
                        group by 
                            bank_id
        ) data_table";
		$whereData = (($_GET['page']) ? "LIMIT ".$end.",".$start : "")."";
		$Data = select_data($columnData,$tableData,$whereData);
		$count_data = count($Data);
		$i = 0;
		while($i < $count_data) {
			$data[] = ['id' => $Data[$i]['data_code'],'col' => $Data[$i]['date_desc'],'total_count' => $count_data,'code' => $Data[$i]['data_code'],'desc' => $Data[$i]['date_desc'],];
			++$i;
		}
		if(empty($data)) {
			$empty[] = ['id' => '','col' => '', 'total_count' => ''];
			echo json_encode($empty);
		} else {
			echo json_encode($data);
		}
    }
    function generateMcKey() {
        $key = bin2hex(openssl_random_pseudo_bytes(16));
        return $key;
    }
    function encrypt($number,$key,$iv) {
        $encrypted = openssl_encrypt($number, 'aes-256-cbc', $key, 0, $iv);
        return $encrypted;
    }
    function decrypt($number,$key,$iv) {
        $decrypted = openssl_decrypt($number, 'aes-256-cbc', $key, 0, $iv);
        return $decrypted;
    }
?>