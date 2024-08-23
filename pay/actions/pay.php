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
    if($_POST['action'] == 'buildPay') {
        $filter_date = $_POST['filter_date'];
        $filter_department = $_POST['filter_department'];
        $filter_employee = $_POST['filter_employee'];
        $pages = $_POST['pages'];
        $filter = "";
        if($filter_date) {
			$date = explode('-',$filter_date);
            $date_st = trim($date[0]);
            $date_ed = trim($date[1]);
            $data_st = substr($date_st,-4).'-'.substr($date_st,3,2).'-'.substr($date_st,0,2);
            $data_ed = substr($date_ed,-4).'-'.substr($date_ed,3,2).'-'.substr($date_ed,0,2);
			$filter .= " and (date(p.round_start) between date('{$data_st}') and date('{$data_ed}') or date(p.round_end) between date('{$data_st}') and date('{$data_ed}') or date(p.paid_day) between date('{$data_st}') and date('{$data_ed}')) ";
		}
        $filter .= ($filter_department) ? " and find_in_set('{$filter_department}',p.dept_id) " : "";
        if($filter_employee) {
            $emp_search = implode(',',$filter_employee);
            $filter .= " and payroll_detail.emp_id in ($emp_search) ";
        }
        $filter_status = $_POST['filter_status'];
        if($pages == 'approve') {
            $ap_join = "left join payroll_approve on payroll_approve.form_id = p.form_id and payroll_approve.status = 0";
            $ap_column = ",group_concat(payroll_approve.approve_flag) as ap_permission_flag,group_concat(payroll_approve.approve_seq) as ap_permission_seq,group_concat(payroll_approve.approve_status) as ap_permission_status,group_concat(payroll_approve.approve_date) as ap_permission_date,group_concat(payroll_approve.approve_remark) as ap_approve_remark";
            $filter_status = ($filter_status == '0') ? 'W' : $filter_status;
            $filter .= " and find_in_set('{$_SESSION['emp_id']}',payroll_approve.emp_id) and ifnull(payroll_approve.approve_status,'W') = '{$filter_status}' ";
        } else {
            $ap_join = "";
            $ap_column = ",'' as ap_permission_flag,'' as ap_permission_seq,'' as ap_permission_status,'' as ap_permission_date,'' as ap_approve_remark";
            if($filter_status <> 'all') {
				$filter .= " and p.payroll_status = '{$filter_status}' ";
			}
        }
        $table = "SELECT 
                    p.form_id,
                    concat(date_format(p.round_start,'%Y/%m/%d'),'-',date_format(p.round_end,'%Y/%m/%d')) as pay_round,
                    date_format(p.paid_day,'%Y/%m/%d') as paid_day,
                    p.dept_id as department_data,
                    p.emp_count,
                    date_format(p.date_modify,'%Y/%m/%d %H:%i:%s') as date_modify,
                    i.emp_pic,
                    p.payroll_status,
                    '{$pages}' as pages_action
                    $ap_column
                FROM 
                    payroll_form p
                LEFT JOIN 
                    m_employee_info i on i.emp_id = p.emp_modify
                $ap_join
                LEFT JOIN 
                    payroll_detail on payroll_detail.form_id = p.form_id and payroll_detail.status = 0
                WHERE 
                    p.comp_id = '{$_SESSION['comp_id']}' and p.status = 0 $filter 
                GROUP BY 
                    p.form_id";
        $primaryKey = 'form_id';
        $columns = array(
            array('db' => 'form_id', 'dt' => 'form_id'),
            array('db' => 'pay_round', 'dt' => 'pay_round'),
            array('db' => 'paid_day', 'dt' => 'paid_day'),
            array('db' => 'date_modify', 'dt' => 'date_modify'),
            array('db' => 'payroll_status', 'dt' => 'payroll_status'),
            array('db' => 'pages_action', 'dt' => 'pages_action'),
            array('db' => 'department_data', 'dt' => 'department_data','formatter' => function ($d, $row) {
                $columnData = "dept_description,ifnull(dept_color,'#FF9900') as dept_color";
                $tableData = "m_department";
                $whereData = "where dept_id in ($d)";
                $Data = select_data($columnData,$tableData,$whereData);
                return $Data;
            }),
            array('db' => 'emp_count', 'dt' => 'emp_count','formatter' => function ($d, $row) {
                return number_format($d);
            }),
            array('db' => 'emp_pic', 'dt' => 'emp_pic','formatter' => function ($d, $row) {
                $emp_pic = $row['emp_pic'];
                $img = str_replace('../','',$emp_pic);
                if(file_exists('../../../'.$img)) {
                    return '/'.$img;
                } else {
                    return GetUrl($img);
                }
            }),
            array('db' => 'ap_permission_flag', 'dt' => 'ap_permission_flag'),
			array('db' => 'ap_permission_seq', 'dt' => 'ap_permission_seq'),
			array('db' => 'ap_permission_status', 'dt' => 'ap_permission_status'),
			array('db' => 'ap_permission_date', 'dt' => 'ap_permission_date'),
			array('db' => 'ap_approve_remark', 'dt' => 'ap_approve_remark')
        );
        $sql_details = array('user' => $db_username,'pass' => $db_pass_word,'db'   => $db_name,'host' => $db_host);
        require($base_include.'/lib/ssp-subquery.class.php');
        echo json_encode(SSP::simple($_POST, $sql_details, $table, $primaryKey, $columns));
		exit();
    }
    if($_GET['action'] == 'buildDepartment') {
		$pages = $_GET['pages'];
		if ($_SESSION["emp_type"] == 'admin' || $_SESSION["emp_type"] == 'sadmin') {
			$sql_team = "";
		} else {
			$team = waterfall($_SESSION['emp_id']);
			$under_crm = head_of_approve_crm($_SESSION['emp_id']);
			if ($team && $under_crm) {
				$team = explode(',', $team);
				$under_crm = explode(',', $under_crm);
				$team = array_unique(array_merge($team, $under_crm), SORT_REGULAR);
				$team = implode(',', $team);
			}
			$sql_team = "and (emp.emp_id = '".$_SESSION['emp_id']."' or emp.emp_id in(".$team."))";
		}
		$keyword = trim($_GET['term']);
		$search = ($keyword) ? " and m.dept_description like '%{$keyword}%' " : "";
		$resultCount = 10;
		$end = ($_GET['page'] - 1) * $resultCount;
		$start = $end + $resultCount;
		$columnData = "*";
		$tableData = "(select distinct m.dept_id,m.dept_description from m_department m left join m_employee emp on m.dept_id = emp.dept_id where m.comp_id = '{$_SESSION['comp_id']}' and m.dept_del is null $sql_team $search) dept";
		$whereData = (($_GET['page']) ? "LIMIT ".$end.",".$start : "")."";
		$Data = select_data($columnData,$tableData,$whereData);
		$count_data = count($Data);
		$i = 0;
		while($i < $count_data) {
			$data[] = ['id' => $Data[$i]['dept_id'],'col' => $Data[$i]['dept_description'],'total_count' => $count_data,'code' => $Data[$i]['dept_id'],'desc' => $Data[$i]['dept_description'],];
			++$i;
		}
		if (empty($data)) {
			$empty[] = ['id' => '','col' => '', 'total_count' => ''];
			echo json_encode($empty);
		} else {
			echo json_encode($data);
		}
    }
    if($_GET['action'] == 'buildEmployee') {
        $filter_department = $_GET['filter_department'];
		$dept_search = ($filter_department) ? " and emp.dept_id = '{$filter_department}' " : "";
		$keyword = trim($_GET['term']);
		$search = ($keyword) ? " and (i.firstname like '%{$keyword}%' or i.firstname_th like '%{$keyword}%' or i.lastname like '%{$keyword}%' or i.lastname_th like '%{$keyword}%') " : "";
		$resultCount = 10;
		$end = ($_GET['page'] - 1) * $resultCount;
		$start = $end + $resultCount;
		$columnData = "*";
        $tableData = "(select distinct i.emp_id as emp_code,CONCAT(IFNULL(i.firstname,i.firstname_th),' ',IFNULL(i.lastname,i.lastname_th)) AS emp_name from m_employee_info i left join m_employee emp on i.emp_id = emp.emp_id  where emp.comp_id = '{$_SESSION['comp_id']}' and emp.emp_del is null $dept_search $search and date(ifnull(emp.emp_end_date,NOW())) >= date(NOW()) and emp.system_type = 1) data_table";
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
    if($_POST['action'] == 'buildFormFromID') {
        $payroll_id = $_POST['payroll_id'];
        $columnData = "date_format(round_start,'%d/%m/%Y') as round_start,
                    date_format(round_end,'%d/%m/%Y') as round_end,
                    date_format(paid_day,'%d/%m/%Y') as paid_day,
                    remark,
                    dept_id,
                    data_income,
                    data_deduction,
                    emp_delete";
        $tableData = "payroll_form";
        $whereData = "where form_id = '{$payroll_id}'";
        $Data = select_data($columnData,$tableData,$whereData);
        $dept_id = explode(',',$Data[0]['dept_id']);
        $data_income = explode(',',$Data[0]['data_income']);
        $data_deduction = explode(',',$Data[0]['data_deduction']);
        $columnDeduction = "";
        $tableDeduction = "";
        $whereDeduction = "";
        $Deduction = select_data($columnDeduction,$tableDeduction,$whereDeduction);
        echo json_encode([
            'status' => true,
            'department_data' => $dept_id,
            'data_income' => $data_income,
            'data_deduction' => $data_deduction,
            'payroll_data' => $Data[0],
        ]);
    }
    if($_POST['action'] == 'buildDepartmentForm') {
        $columnData = "dept_id,dept_description";
        $tableData = "m_department";
        $whereData = "where comp_id = '{$_SESSION['comp_id']}' and dept_del is null and status = 0 order by 2 asc";
        $Data = select_data($columnData,$tableData,$whereData);
        $count_data = count($Data);
        $i_data = 0;
        while($i_data < $count_data) {
            $dept_id = $Data[$i_data]['dept_id'];
            $columnEmp = "*";
            $tableEmp = "m_employee";
            $whereEmp = "WHERE comp_id = '{$_SESSION['comp_id']}' and emp_del is null and date(ifnull(emp_end_date,NOW())) >= date(NOW()) and system_type = 1 and dept_id = '{$dept_id}'";
            $Emp = select_data($columnEmp,$tableEmp,$whereEmp);
            $count_emp = count($Emp);
            $Data[$i_data]['count_emp'] = number_format($count_emp);
            ++$i_data;
        }
        echo json_encode(['department_data' => $Data]);
    }
    if($_POST['action'] == 'buildIncome') {
        $columnData = "income_id,income_name_en,income_name_th,income_flag";
        $tableData = "payroll_income_master";
        $whereData = "where status = 0 order by 1 asc";
        $Data = select_data($columnData,$tableData,$whereData);
        echo json_encode(['income_data' => $Data]);
    }
    if($_POST['action'] == 'buildDeduction') {
        $columnData = "deduction_id,deduction_name_en,deduction_name_th,deduction_flag";
        $tableData = "payroll_deduction_master";
        $whereData = "where status = 0 order by 1 asc";
        $Data = select_data($columnData,$tableData,$whereData);
        echo json_encode(['deduction_data' => $Data]);
    }
    if($_POST['action'] == 'buildEmployeeTable') {
        $filter_department = $_POST['filter_department'];
        $emp_delete = $_POST['emp_delete'];
        $filter = "";
        if($filter_department) {
            $filter .= " and emp.dept_id in ($filter_department) ";
        } else {
            $filter .= " and emp.dept_id in (0) ";
        }
        if($emp_delete) {
            $filter .= " and emp.emp_id not in ($emp_delete) ";
        }
        $table = "SELECT 
                    emp.emp_id,
                    emp.emp_code,
                    i.firstname,
                    i.lastname,
                    i.firstname_th,
                    i.lastname_th,
                    CONCAT(IFNULL(i.firstname,i.firstname_th),' ',IFNULL(i.lastname,i.lastname_th)) AS emp_name,
                    i.emp_pic,
                    dept.dept_description
                FROM 
                    m_employee emp 
                LEFT JOIN 
                    m_employee_info i on i.emp_id = emp.emp_id
                LEFT JOIN 
                    m_department dept on dept.dept_id = emp.dept_id
                WHERE 
                    emp.comp_id = '{$_SESSION['comp_id']}' and emp.emp_del is null and date(ifnull(emp.emp_end_date,NOW())) >= date(NOW()) and emp.system_type = 1 $filter";
        $primaryKey = 'emp_id';
        $columns = array(
            array('db' => 'emp_id', 'dt' => 'emp_id'),
            array('db' => 'emp_code', 'dt' => 'emp_code'),
            array('db' => 'firstname', 'dt' => 'firstname'),
            array('db' => 'lastname', 'dt' => 'lastname'),
            array('db' => 'firstname_th', 'dt' => 'firstname_th'),
            array('db' => 'lastname_th', 'dt' => 'lastname_th'),
            array('db' => 'emp_name', 'dt' => 'emp_name'),
            array('db' => 'dept_description', 'dt' => 'dept_description'),
            array('db' => 'emp_pic', 'dt' => 'emp_pic','formatter' => function ($d, $row) {
                $emp_pic = $row['emp_pic'];
                $img = str_replace('../','',$emp_pic);
                if(file_exists('../../../'.$img)) {
                    return '/'.$img;
                } else {
                    return GetUrl($img);
                }
            }),
        );
        $sql_details = array('user' => $db_username,'pass' => $db_pass_word,'db'   => $db_name,'host' => $db_host);
        require($base_include.'/lib/ssp-subquery.class.php');
        echo json_encode(SSP::simple($_POST, $sql_details, $table, $primaryKey, $columns));
		exit();
    }
    if($_POST['action'] == 'buildEmployeeDelete') {
        $filter_department = $_POST['filter_department'];
        $emp_delete = $_POST['emp_delete'];
        if($emp_delete) {
            $filter .= " and emp.emp_id in ($emp_delete) ";
        } else {
            $filter .= " and emp.emp_id in (0) ";
        }
        $table = "SELECT 
                    emp.emp_id,
                    emp.emp_code,
                    i.firstname,
                    i.lastname,
                    i.firstname_th,
                    i.lastname_th,
                    CONCAT(IFNULL(i.firstname,i.firstname_th),' ',IFNULL(i.lastname,i.lastname_th)) AS emp_name,
                    i.emp_pic,
                    dept.dept_description
                FROM 
                    m_employee emp 
                LEFT JOIN 
                    m_employee_info i on i.emp_id = emp.emp_id
                LEFT JOIN 
                    m_department dept on dept.dept_id = emp.dept_id
                WHERE 
                    emp.comp_id = '{$_SESSION['comp_id']}' and emp.emp_del is null and date(ifnull(emp.emp_end_date,NOW())) >= date(NOW()) and emp.system_type = 1 $filter";
        $primaryKey = 'emp_id';
        $columns = array(
            array('db' => 'emp_id', 'dt' => 'emp_id'),
            array('db' => 'emp_code', 'dt' => 'emp_code'),
            array('db' => 'firstname', 'dt' => 'firstname'),
            array('db' => 'lastname', 'dt' => 'lastname'),
            array('db' => 'firstname_th', 'dt' => 'firstname_th'),
            array('db' => 'lastname_th', 'dt' => 'lastname_th'),
            array('db' => 'emp_name', 'dt' => 'emp_name'),
            array('db' => 'dept_description', 'dt' => 'dept_description'),
            array('db' => 'emp_pic', 'dt' => 'emp_pic','formatter' => function ($d, $row) {
                $emp_pic = $row['emp_pic'];
                $img = str_replace('../','',$emp_pic);
                if(file_exists('../../../'.$img)) {
                    return '/'.$img;
                } else {
                    return GetUrl($img);
                }
            }),
        );
        $sql_details = array('user' => $db_username,'pass' => $db_pass_word,'db'   => $db_name,'host' => $db_host);
        require($base_include.'/lib/ssp-subquery.class.php');
        echo json_encode(SSP::simple($_POST, $sql_details, $table, $primaryKey, $columns));
		exit();
    }
    if($_GET['action'] == 'saveForm') {
        $payroll_id = $_POST['payroll_id'];
        $start_date = getFormatDate($_POST['start_date']);
        $end_date = getFormatDate($_POST['end_date']);
        $payment_date = getFormatDate($_POST['payment_date']);
        $remark = escape_string($_POST['remark']);
        $dept_id = $_POST['dept_id'];
        $income_id = implode(',',$_POST['income_id']);
        $deduction_id = implode(',',$_POST['deduction_id']);
        $emp_delete = $_POST['emp_delete'];
        $dept_save = implode(',',$dept_id);
        $emp_search = "";
        if($emp_delete <> '') {
            $emp_search = " and emp_id not in ($emp_delete)";
        }
        $columnEmp = "emp_id";
        $tableEmp = "m_employee";
        $whereEmp = "where comp_id = '{$_SESSION['comp_id']}' and emp_del is null and date(ifnull(emp_end_date,NOW())) >= date(NOW()) and system_type = 1 and dept_id in ($dept_save) $emp_search";
        $Emp = select_data($columnEmp,$tableEmp,$whereEmp);
        $count_emp = count($Emp);
        if($payroll_id) {
            $tableUpdData = "payroll_form";
            $valueUpdData = "round_start = date('{$start_date}'),
                        round_end = date('{$end_date}'),
                        paid_day = date('{$payment_date}'),
                        dept_id = '{$dept_save}',
                        emp_delete = '{$emp_delete}',
                        data_income = '{$income_id}',
                        data_deduction = '{$deduction_id}',
                        remark = '{$remark}',
                        emp_modify = '{$_SESSION['emp_id']}',
                        date_modify = NOW(),
                        emp_count = '{$count_emp}'";
            $whereUpdData = "form_id = '{$payroll_id}'";
            update_data($tableUpdData,$valueUpdData,$whereUpdData);
        } else {
            $mc_key = generateMcKey();
            $tableInsData = "payroll_form";
            $columnInsData = "(
                comp_id,
                round_start,
                round_end,
                paid_day,
                dept_id,
                emp_delete,
                data_income,
                data_deduction,
                remark,
                payroll_status,
                status,
                emp_create,
                date_create,
                emp_modify,
                date_modify,
                emp_count,
                mc_key
            )";
            $valueInsData = "(
                '{$_SESSION['comp_id']}',
                date('{$start_date}'),
                date('{$end_date}'),
                date('{$payment_date}'),
                '{$dept_save}',
                '{$emp_delete}',
                '{$income_id}',
                '{$deduction_id}',
                '{$remark}',
                0,
                0,
                '{$_SESSION['emp_id']}',
                NOW(),
                '{$_SESSION['emp_id']}',
                NOW(),
                '{$count_emp}',
                '{$mc_key}'
            )";
            $payroll_id = insert_data($tableInsData,$columnInsData,$valueInsData);
        }   
        $tableUpdOld = "payroll_detail";
        $valueUpdOld = "status = 1,emp_modify = '{$_SESSION['emp_id']}',date_modify = NOW()";
        $whereUpdOld = "form_id = '{$payroll_id}'";
        update_data($tableUpdOld,$valueUpdOld,$whereUpdOld);
        $i_emp = 0;
        while($i_emp < $count_emp) {
            $emp_id = $Emp[$i_emp]['emp_id'];
            $columnData = "*";
            $tableData = "payroll_detail";
            $whereData = "where emp_id = '{$emp_id}' and form_id = '{$payroll_id}'";
            $Data = select_data($columnData,$tableData,$whereData);
            $count_data = count($Data);
            if($count_data > 0) {
                $tableUpdData = "payroll_detail";
                $valueUpdData = "data_income = null,data_deduction = null,social_id = null,amount = null,status = 0,emp_modify = '{$_SESSION['emp_id']}',date_modify = NOW()";
                $whereUpdData = "emp_id = '{$emp_id}' and form_id = '{$payroll_id}'";
                update_data($tableUpdData,$valueUpdData,$whereUpdData);
            } else {
                $tableInsData = "payroll_detail";
                $columnInsData = "(
                    form_id,
                    emp_id,
                    emp_create,
                    date_create,
                    emp_modify,
                    date_modify
                )";
                $valueInsData = "(
                    '{$payroll_id}',
                    '{$emp_id}',
                    '{$_SESSION['emp_id']}',
                    NOW(),
                    '{$_SESSION['emp_id']}',
                    NOW()
                )";
                insert_data($tableInsData,$columnInsData,$valueInsData);
            }
            ++$i_emp;
        }
        echo json_encode(['status' => true,'payroll_id' => $payroll_id]);
    }
    if($_POST['action'] == 'delPayroll') {
        $payroll_id = $_POST['payroll_id'];
        $tableUpdData = "payroll_form";
        $valueUpdData = "status = 1,emp_modify = '{$_SESSION['emp_id']}',date_modify = NOW()";
        $whereUpdData = "form_id = '{$payroll_id}'";
        update_data($tableUpdData,$valueUpdData,$whereUpdData);
        echo json_encode(['status' => true]);
    }
    if($_POST['action'] == 'getApprovalStep') {
        $columnData = "distinct approval_id";
		$tableData = "m_approval_master";
		$whereData = "where approval_module = 'payroll' and comp_id = '{$_SESSION['comp_id']}' and status = 0";
		$Data = select_data($columnData,$tableData,$whereData);
		$count_data = count($Data);
		$status = ($count_data > 0) ? true : false;
        echo json_encode(['status' => $status]);
    }
    if($_POST['action'] == 'buildPayrollTable') {
        $payroll_id = $_POST['payroll_id'];
        $columnMcKey = "mc_key";
        $tableMcKey = "payroll_form";
        $whereMcKey = "where form_id = '{$payroll_id}'";
        $McKey = select_data($columnMcKey,$tableMcKey,$whereMcKey);
        $mc_key = $McKey[0]['mc_key'];
        $columnDate = "round_start,round_end";
        $tableDate = "payroll_form";
        $whereDate = "where form_id = '{$payroll_id}'";
        $Date = select_data($columnDate,$tableDate,$whereDate);
        $round_start = $Date[0]['round_start'];
        $round_end = $Date[0]['round_end'];
        $columnData = "data_income,data_deduction";
        $tableData = "payroll_form";
        $whereData = "where form_id = '{$payroll_id}'";
        $Data = select_data($columnData,$tableData,$whereData);
        $data_income = $Data[0]['data_income'];
        $data_deduction = $Data[0]['data_deduction'];
        $columnIncome = "income_id,income_name_en,income_name_th,income_flag,income_default";
        $tableIncome = "payroll_income_master";
        $whereIncome = "where income_id in ($data_income)";
        $Income = select_data($columnIncome,$tableIncome,$whereIncome);
        $count_income = count($Income);
        $columnDeduction = "deduction_id,deduction_name_en,deduction_name_th,deduction_flag,deduction_module,deduction_default";
        $tableDeduction = "payroll_deduction_master";
        $whereDeduction = "where deduction_id in ($data_deduction)";
        $Deduction = select_data($columnDeduction,$tableDeduction,$whereDeduction);
        $count_deduction = count($Deduction);
        $columnEmp = "emp.emp_id,
                    CONCAT(IFNULL(i.firstname,i.firstname_th),' ',IFNULL(i.lastname,i.lastname_th)) AS emp_name,
                    emp.emp_code,
                    payroll_detail.data_income,
                    payroll_detail.data_deduction,
                    payroll_detail.amount";
        $tableEmp = "m_employee emp";
        $whereEmp = "left join 
                        m_employee_info i on i.emp_id = emp.emp_id 
                    left join 
                        payroll_detail on payroll_detail.emp_id = emp.emp_id 
                    where 
                        payroll_detail.form_id = '{$payroll_id}' and payroll_detail.status = 0 
                    group by 
                        emp.emp_id 
                    order by 
                        emp.emp_code asc";
        $Emp = select_data($columnEmp,$tableEmp,$whereEmp);
        $count_emp = count($Emp);
        $i_emp = 0;
        while($i_emp < $count_emp) {
            $emp_id = $Emp[$i_emp]['emp_id'];
            $data_income = explode(',',$Emp[$i_emp]['data_income']);
            $data_deduction = explode(',',$Emp[$i_emp]['data_deduction']);
            $amount = explode(',',$Emp[$i_emp]['amount']);
            $amountLen = decryptData($amount[0],$mc_key);
            $amount_number = '';
            $i = 1;
            while($i <= $amountLen) {
                $amount_number .= decryptData($amount[$i],$mc_key);
                ++$i;
            }
            $base_salary = 0;
            $amount_number = ($amount_number) ? $amount_number : 0;
            $Emp[$i_emp]['amount'] = number_format($amount_number,2);
            $data = [];
            $i_income = 0;
            while($i_income < $count_income) {
                $data[] = (!empty($data_income[$i_income])) ? decryptData($data_income[$i_income],$mc_key) : 0;
                if($Income[$i_income]['income_default'] == "Y") {
                    $base_salary = (!empty($data_income[$i_income])) ? decryptData($data_income[$i_income],$mc_key) : 0;
                } else {
                    $base_salary = 0;
                }
                ++$i_income;
            }
            $i_deduction = 0;
            while($i_deduction < $count_deduction) {
                $deduction_flag = $Deduction[$i_deduction]['deduction_flag'];
                $deduction_module = $Deduction[$i_deduction]['deduction_module'];
                if(!empty($data_deduction[$i_deduction])) {
                    $data[] = decryptData($data_deduction[$i_deduction],$mc_key);
                } else {
                    if($deduction_flag == 'O') {
                        if($deduction_module == 'time') {
                            $columnLate = "SUM(FLOOR(TIME_TO_SEC(time_late) / 60)) AS total_time";
                            $tableLate = "temp_attendance";
                            $whereLate = "where emp_id = '{$emp_id}' and date(date_time) BETWEEN date('{$round_start}') and date('{$round_end}')";
                            $Late = select_data($columnLate,$tableLate,$whereLate);
                            $total_time = $Late[0]['total_time'];
                            $data[] = ($total_time) ? $total_time : 0;
                        } else if($deduction_module == 'work') {
                            $columnLeave = "sum(abs_request_form.request_total_time) as total_time";
                            $tableLeave = "abs_request_form";
                            $whereLeave = "left join 
                                            abs_request_approve on abs_request_approve.request_id = abs_request_form.request_id 
                                        where 
                                            abs_request_form.emp_id = '{$emp_id}' and abs_request_form.request_status is null and abs_request_approve.approve_del is null and (date(request_from_date) BETWEEN date('{$round_start}') and date('{$round_end}') or date(request_to_date) BETWEEN date('{$round_start}') and date('{$round_end}')) and abs_request_approve.approve_status = 'N'";
                            $Leave = select_data($columnLeave,$tableLeave,$whereLeave);
                            $total_time = $Leave[0]['total_time'];
                            $data[] = ($total_time) ? $total_time : 0;
                        } else {
                            $data[] = 0;
                        }
                    } else if($deduction_flag == 'S') {
                        if($base_salary > 0) {
                            $max = 15000;
                            if($base_salary >= $max) {
                                $base = $max;
                            } else {
                                $base = $base_salary;
                            }
                            $data[] = $base*0.05;
                        } else {
                            $data[] = 0;
                        }
                    }else {
                        $data[] = 0;
                    }
                }
                ++$i_deduction;
            }
            $Emp[$i_emp]['data_money'] = $data;
            ++$i_emp;
        }
        echo json_encode([
            'status' => true,
            'income_data' => $Income,
            'deduction_data' => $Deduction,
            'emp_data' => $Emp
        ]);
    }
    if($_POST['action'] == 'saveMoney') {
        $payroll_id = $_POST['payroll_id'];
        $columnMcKey = "mc_key";
        $tableMcKey = "payroll_form";
        $whereMcKey = "where form_id = '{$payroll_id}'";
        $McKey = select_data($columnMcKey,$tableMcKey,$whereMcKey);
        $mc_key = $McKey[0]['mc_key'];
        $emp_id = $_POST['emp_id'];
        $money_data = $_POST['money_data'];
        $money_type_data = $_POST['money_type_data'];
        $income_data = [];
        $deduction_data = [];
        $sumIncome = 0;
        $sumDeduction = 0;
        $i = 0;
        while($i < count($money_data)) {
            $money_type = $money_type_data[$i];
            $money_real = str_replace(',','',$money_data[$i]);
            $money = mc_encrypt($money_real,$mc_key);
            if($money_type == 'income') {
                $income_data[] = $money;
                $sumIncome = $sumIncome+$money_real;
            }
            if($money_type == 'deduction') {
                $deduction_data[] = $money;
                $sumDeduction = $sumDeduction+$money_real;
            }
            ++$i;
        }
        $amount = $sumIncome-$sumDeduction;
        $amount = number_format($amount,2);
        $amount_val = $amount;
        $amount = str_replace(',','',$amount);
        $amount_length = strlen($amount);
        $amount_data = [];
        $amount_first = mc_encrypt($amount_length,$mc_key);
        $amount_data[] = $amount_first;
        $i = 0;
        while($i < $amount_length) {
            $pos = substr($amount,$i,1);
            $amount_data[] =  mc_encrypt($pos,$mc_key);
            ++$i;
        }
        $amount_save = escape_string(implode(',',$amount_data));
        $income_save = escape_string(implode(',',$income_data));
        $deduction_save = escape_string(implode(',',$deduction_data));
        $tableUpdData = "payroll_detail";
        $valueUpdData = "data_income = '{$income_save}',data_deduction = '{$deduction_save}',emp_modify = '{$_SESSION['emp_id']}',date_modify = NOW(),amount = '{$amount_save}'";
        $whereUpdData = "form_id = '{$payroll_id}' and emp_id = '{$emp_id}'";
        update_data($tableUpdData,$valueUpdData,$whereUpdData);
        echo json_encode(['status' => true,'amount_val' => $amount_val]);
    }
    if($_POST['action'] == 'showDetailTextbox') {
        $emp_id = $_POST['emp_id'];
        $columnEmp = "emp.emp_code,
                    CONCAT(IFNULL(i.firstname,i.firstname_th),' ',IFNULL(i.lastname,i.lastname_th)) AS emp_name";
        $tableEmp = "m_employee emp";
        $whereEmp = "left join 
                        m_employee_info i on i.emp_id = emp.emp_id 
                    where 
                        emp.emp_id = '{$emp_id}'";
        $Emp = select_data($columnEmp,$tableEmp,$whereEmp);
        $money_type = $_POST['money_type'];
        $money_id = $_POST['money_id'];
        if($money_type == 'income') {
            $columnMoney = "income_name_en as money_name_en,income_name_th as money_name_th";
            $tableMoney = "payroll_income_master";
            $whereMoney = "where income_id = '{$money_id}'";
        } else {
            $columnMoney = "deduction_name_en as money_name_en,deduction_name_th as money_name_th";
            $tableMoney = "payroll_deduction_master";
            $whereMoney = "where deduction_id = '{$money_id}'";
        }
        $Money = select_data($columnMoney,$tableMoney,$whereMoney);
        echo json_encode(['status' => true,'emp_data' => $Emp[0],'money_data' => $Money[0]]);
    }
    if($_POST['action'] == 'buildTimeline') {
        $payroll_id = $_POST['payroll_id'];
        $columnData = "payroll_status";
        $tableData = "payroll_form";
        $whereData = "where form_id = '{$payroll_id}'";
        $Data = select_data($columnData,$tableData,$whereData);
        $payroll_status = $Data[0]['payroll_status'];
        $columnEmp = "*";
        $tableEmp = "payroll_detail";
        $whereEmp = "where form_id = '{$payroll_id}' and status = 0";
        $Emp = select_data($columnEmp,$tableEmp,$whereEmp);
        $count_emp = count($Emp);
        echo json_encode(['status' => true,'payroll_status' => $payroll_status,'count_emp' => $count_emp]);
    }
    if($_POST['action'] == 'sendToApprove') {
        $payroll_id = $_POST['payroll_id'];
        $tableUpdData = "payroll_form";
        $valueUpdData = "payroll_status = 1,send_ap_date = NOW(),emp_modify = '{$_SESSION['emp_id']}',date_modify = NOW()";
        $whereUpdData = "form_id = '{$payroll_id}'";
        update_data($tableUpdData,$valueUpdData,$whereUpdData);
        $approval_data = getApprovalProcess('payroll','',$_SESSION['comp_id'],'','','','',$emp_id);
        $columnApData = "approve_id";
        $tableApData = "payroll_approve";
        $whereApData = "where form_id = '{$payroll_id}' order by approve_id asc";
        $ApData = select_data($columnApData,$tableApData,$whereApData);
        $count_apdata = count($ApData);
        $ap_array = array();
        $i_apdata = 0;
        while($i_apdata < $count_apdata) {
            $approve_id = $ApData[$i_apdata]['approve_id'];
            array_push($ap_array,$approve_id);
            ++$i_apdata;
        }
        if($approval_data) {
            $i_approve = 0;
            while($i_approve < count($approval_data)) {
                $permission = $approval_data[$i_approve]['permission'];
               	$step = $approval_data[$i_approve]['step'];
                $sequence = $approval_data[$i_approve]['sequence'];
                $employee = $approval_data[$i_approve]['employee'];
                $position = $approval_data[$i_approve]['position'];
                $verify = $approval_data[$i_approve]['verify'];
                $type = $approval_data[$i_approve]['type'];
                if($ap_array[$i_approve]) {
                    $tableUpdData = "payroll_approve";
                    $valueUpdData = "emp_id = '{$employee}',
                        approve_position = '{$position}',
                        approve_permission = '{$permission}',
                        approve_flag = '{$verify}',
                        approve_seq = '{$sequence}',
                        approve_status = 'W',
                        approve_date = null,
                        approve_remark = null,
                        status = 0,
                        emp_modify = '{$_SESSION['emp_id']}',
                        date_modify = NOW()";
                    $whereUpdData = "approve_id = '{$ap_array[$i_approve]}'";
                    update_data($tableUpdData,$valueUpdData,$whereUpdData);
                } else {
                    $tableInsData = "payroll_approve";
                    $columnInsData = "(
                        form_id,
                        emp_id,
                        comp_id,
                        approve_position,
                        approve_permission,
                        approve_flag,
                        approve_seq,
                        approve_status,
                        approve_date,
                        approve_remark,
                        status,
                        emp_create,
                        date_create,
                        emp_modify,
                        date_modify
                    )";
                    $valueInsData = "(
                        '{$payroll_id}',
                        '{$employee}',
                        '{$_SESSION['comp_id']}',
                        '{$position}',
                        '{$permission}',
                        '{$verify}',
                        '{$sequence}',
                        'W',
                        null,
                        null,
                        0,
                        '{$_SESSION['emp_id']}',
                        NOW(),
                       '{$_SESSION['emp_id']}',
                        NOW()
                    )";
                    insert_data($tableInsData,$columnInsData,$valueInsData);
                }
                ++$i_approve;
            }
        }
        echo json_encode(['status' => true]);
    }
    if($_POST['action'] == 'paidData') {
        $payroll_id = $_POST['payroll_id'];
        $tableUpdData = "payroll_form";
        $valueUpdData = "payroll_status = 6,send_ap_date = NOW(),emp_modify = '{$_SESSION['emp_id']}',date_modify = NOW()";
        $whereUpdData = "form_id = '{$payroll_id}'";
        update_data($tableUpdData,$valueUpdData,$whereUpdData);
        echo json_encode(['status' => true]);
    }
    if($_POST['action'] == 'approveData') {
        $migrationId = (is_array($_POST['payroll_id'])) ? implode(',',$_POST['payroll_id']) : $_POST['payroll_id'];
		$id = explode(',',$migrationId);
		$option = $_POST['option'];
		$approve_remark = escape_string($_POST['approve_remark']);
        $i = 0;
		while($i < count($id)) {
			$migration_id = $id[$i];
			$columnSpecial = "*";
			$tableSpecial = "payroll_approve";
			$whereSpecial = "where find_in_set('{$_SESSION['emp_id']}',emp_id) and ifnull(approve_permission,'A') = 'F' and form_id = '{$migration_id}'";
			$Special = select_data($columnSpecial,$tableSpecial,$whereSpecial);
			$count_special = count($Special);
			if($count_special > 0) {
				$tableUpdData = "payroll_approve";
				$valueUpdData = "approve_status = '{$option}',approve_remark = '{$approve_remark}',approve_date = NOW()";
				$whereUpdData = "form_id = '{$migration_id}' and ifnull(approve_status,'W') <> 'Y'";
				update_data($tableUpdData,$valueUpdData,$whereUpdData);
			} else {
				$tableUpdData = "payroll_approve";
				$valueUpdData = "approve_status = '{$option}',approve_date = NOW(),approve_remark = '{$approve_remark}'";
				$whereUpdData = "form_id = '{$migration_id}' and find_in_set('{$_SESSION['emp_id']}',emp_id) and status = 0";
				update_data($tableUpdData,$valueUpdData,$whereUpdData);
			}
			$result = getApprovalResult('payroll_approve','form_id','approve_status','approve_permission',$migration_id);
			switch($result) {
				case 'Y':
					$migration_status = 3;
				break;
				case 'N':
					$migration_status = 4;
				break;
				default:
					$migration_status = 2;
			}
			$migration_status = ($option == 'I') ? 5 : $migration_status;
			$tableUpdData = "payroll_form";
			$valueUpdData = "payroll_status = '{$migration_status}',emp_modify = '{$_SESSION['emp_id']}',date_modify = NOW()";
			$whereUpdData = "form_id = '{$migration_id}'";
			update_data($tableUpdData,$valueUpdData,$whereUpdData);
			++$i;
		}
		echo json_encode(['status' => true]);
    }
    if($_POST['action'] == 'viewDeduction') {
        $emp_id = $_POST['emp_id'];
        $payroll_id = $_POST['payroll_id'];
        $deduction_module = $_POST['deduction_module'];
        $columnEmp = "CONCAT(IFNULL(firstname,firstname_th),' ',IFNULL(lastname,lastname_th)) AS emp_name";
        $tableEmp = "m_employee_info";
        $whereEmp = "where emp_id = '{$emp_id}'";
        $Emp = select_data($columnEmp,$tableEmp,$whereEmp);
        $emp_name = $Emp[0]['emp_name'];
        $columnPayroll = "concat(date_format(round_start,'%Y/%m/%d'),' - ',date_format(round_end,'%Y/%m/%d')) as round_date,round_start,round_end";
        $tablePayroll = "payroll_form";
        $wherePayroll = "where form_id = '{$payroll_id}'";
        $Payroll = select_data($columnPayroll,$tablePayroll,$wherePayroll);
        $round_date = $Payroll[0]['round_date'];
        $round_start = $Payroll[0]['round_start'];
        $round_end = $Payroll[0]['round_end'];
        if($deduction_module == 'time') {
            $owner = $emp_id;
            $data_st = $round_start;
            $data_ed = $round_end;
            $dayList = createDateRangeArray($data_st,$data_ed);
            $dataLeave = GetLeaveData($owner,$data_st,$data_ed);
            $expandLeave = expandLeave($dataLeave);
            $dataStamp = GetStampData($owner,$data_st,$data_ed);
            $data = array();
            $i = 0;
            while($i < count($dayList)){
                $day = $dayList[$i];
                $columnDateFormat = "date_format('{$day}','%a %b,%d %Y') as day_show,date_format('{$day}','%a') as end_week";
                $tableDateFormat = "";
                $whereDateFormat = "";
                $DateFormat = select_data($columnDateFormat,$tableDateFormat,$whereDateFormat);
                $day_show = $DateFormat[0]['day_show'];
                $end_week = $DateFormat[0]['end_week'];
                $off_status = (in_array($day,array_column($day_off, 'off_date'))) ? 'Y' : 'N';
                if($off_status == 'N'){
                    $etc = '';
                }else{
                    $pos = array_search($day,array_column($day_off, 'off_date'));
                    $etc = $day_off[$pos]['off_name'];
                }
                $ownerList = array();
                $j = 0;
                while($j < count($owner)){
                    $empId = $owner[$j];
                    $columnOwner = "emp_pic";
                    $tableOwner = "m_employee_info";
                    $whereOwner = "where emp_id = '{$empId}'";
                    $Owner = select_data($columnOwner,$tableOwner,$whereOwner);
                    $img = $Owner[0]['emp_pic'];
                    if(file_exists('../../../'.$emp_pic)) {
                        $emp_pic = '/'.$img;
                    } else {
                        $emp_pic = GetUrl($img);
                    }
                    $ownerList[$j]['emp_id'] = $empId;
                    $ownerList[$j]['date_select'] = str_replace('-','',$day); 
                    $ownerList[$j]['emp_pic'] = $emp_pic;
                    $time_check = $day.'_'.$empId;
                    if(in_array($time_check,array_column($dataStamp, 'time_key'))){
                        $pos = array_search($time_check,array_column($dataStamp, 'time_key'));
                        $time_in = $dataStamp[$pos]['time_in'];
                        $time_out = $dataStamp[$pos]['time_out'];
                        $time_out = ($time_in == $time_out) ? '' : $time_out;
                    }else{
                        $time_in = '';
                        $time_out = '';
                    }
                    $ownerList[$j]['time_in'] = $time_in;
                    $ownerList[$j]['time_out'] = $time_out;
                    $ShiftWork = ShiftWork($empId,$_SESSION['comp_id'],$day);
                    $ownerList[$j]['time_count'] = $ShiftWork['time_count'];
                    $ownerList[$j]['check_in'] = $ShiftWork['check_in'];
                    $ownerList[$j]['check_out'] = $ShiftWork['check_out'];
                    $ownerList[$j]['break_in'] = $ShiftWork['break_in'];
                    $ownerList[$j]['break_out'] = $ShiftWork['break_out'];
                    if($time_in && $time_out){
                        $timeFirst  = strtotime($time_in);
                        $timeSecond = strtotime($time_out);
                        $differenceInSeconds = $timeSecond - $timeFirst;
                        $balance = gmdate("H:i:s", $differenceInSeconds);
                    }else{
                        $balance = ($time_in || $time_out) ? '<code>N/A</code>' : '';
                    }
                    if($time_in && $ShiftWork['time_count'] > 0){
                        $st_late = $day.' '.$ShiftWork['check_in'].':00';
                        $ed_late = $day.' '.$time_in;
                        $timeFirst_late  = strtotime($st_late);
                        $timeSecond_late = strtotime($ed_late);
                        $differenceInSeconds_late = $timeSecond_late - $timeFirst_late;
                        $late = ($ShiftWork['time_count'] > 0) ? ($differenceInSeconds_late > 0) ? floor($differenceInSeconds_late/60) : '' : '<code>N/A</code>';
                    }else{
                        $late = ($time_in || $time_out) ? '<code>N/A</code>' : '';
                    }
                    if($time_out && $ShiftWork['time_count'] > 0){
                        $st_early = $day.' '.$ShiftWork['check_out'].':00';
                        $ed_early = $day.' '.$time_out;
                        $timeFirst_early  = strtotime($st_early);
                        $timeSecond_early = strtotime($ed_early);
                        $differenceInSeconds_early = $timeFirst_early - $timeSecond_early;
                        $early = ($ShiftWork['time_count'] > 0) ? ($differenceInSeconds_early > 0) ? floor($differenceInSeconds_early/60) : '' : '<code>N/A</code>';
                    }else{
                        $early = ($time_in || $time_out) ? '<code>N/A</code>' : '';
                    }
                    if($time_out && $ShiftWork['time_count'] > 0){
                        $st_over = $day.' '.$ShiftWork['check_out'].':00';
                        $ed_over = $day.' '.$time_out;
                        $timeFirst_over  = strtotime($st_over);
                        $timeSecond_over = strtotime($ed_over);
                        $differenceInSeconds_over = $timeSecond_over - $timeFirst_over;
                        $over_time = ($ShiftWork['time_count'] > 0) ? ($differenceInSeconds_over > 0) ? floor($differenceInSeconds_over/60) : '' : '<code>N/A</code>';
                    }else{
                        $over_time = ($time_in || $time_out) ? '<code>N/A</code>' : '';
                    }
                    $ownerList[$j]['balance'] = $balance;
                    $ownerList[$j]['break'] = $break;
                    $ownerList[$j]['late'] = $late;
                    $ownerList[$j]['early'] = $early;
                    $ownerList[$j]['over_time'] = $over_time;
                    $remark = array();
                    $leave_check = $day.'_'.$empId;
                    if(in_array($leave_check,array_column($expandLeave, 'key'))){
                        $leave = 1;
                        $posLeave = array_search($leave_check,array_column($expandLeave, 'key'));
                        $leave_type = $expandLeave[$posLeave]['type'];
                        $approve_comment = $expandLeave[$posLeave]['comment'];
                        $leave_time_st = $expandLeave[$posLeave]['st'];
                        $leave_time_ed = $expandLeave[$posLeave]['ed'];
                        if($leave_time_st && $leave_time_ed){
                            $rm = $leave_type.'<br><span style="font-size:11px;">'.$leave_time_st.'-'.$leave_time_ed.'<br>'.$approve_comment.'</span>';
                        }else{
                            $rm = '';
                        }
                        array_push($remark,'<span class="badge bg-leave badge2" lang="en">'.$rm.'</span>');
                    }else{
                        $leave = 0;
                    }
                    $ownerList[$j]['leave'] = $leave;
                    $ownerList[$j]['remark'] = implode(',',$remark);
                    ++$j;
                }
                $data[] = ['day' => $day_show,'end_week' => $end_week,'off_status' => $off_status,'owner_length' => count($owner),"ownerList" => $ownerList,"etc" => $etc,"dataStamp" => $dataStamp,"dataLeave" => $dataLeave,'expandLeave' => $expandLeave];
                ++$i;
            }
        } else {
            $data[] = [];
        }
        echo json_encode([
            'status' => true,
            'emp_name' => $emp_name,
            'round_date' => $round_date,
            'table_data' => $data
        ]);
    }
    if($_POST['action'] == 'buildWork') {
        $emp_id = $_POST['emp_id'];
        $payroll_id = $_POST['payroll_id'];
        $columnPayroll = "round_start,round_end";
        $tablePayroll = "payroll_form";
        $wherePayroll = "where form_id = '{$payroll_id}'";
        $Payroll = select_data($columnPayroll,$tablePayroll,$wherePayroll);
        $round_start = $Payroll[0]['round_start'];
        $round_end = $Payroll[0]['round_end'];
		$date_start = $round_start;
		$date_end = $round_end;
		$filter1 = "AND abs_request_form.request_from_date >= '{$date_start}' AND abs_request_form.request_to_date <= '{$date_end}' ";
		$filter2 = "AND abs_floating_form.floating_from_date >= '{$date_start}' AND abs_floating_form.floating_to_date <= '{$date_end}' ";
		$filter3 = "AND abs_floating_leave_form.floating_leave_from_date >= '{$date_start}' AND abs_floating_leave_form.floating_leave_to_date <= '{$date_end}' ";
		$filter4 = "AND time_request_ot.request_ot_in >= '{$date_start}' AND time_request_ot.request_ot_out <= '{$date_end}' ";
		$table = "SELECT * FROM ((
				select  
					concat('leave',abs_request_form.request_id) as id, 
					abs_request_form.request_id as ref_id, 
					'leave' as type_work, 
					abs_leave_default.leave_type_id as type_id, 
					abs_leave_default.leave_type_name_en as type_name, 
					abs_leave_default.leave_type_name_th as type_name_th, 
					date_format(abs_request_form.create_datetime,'%d/%m/%Y %H:%i:%s') as date_create,
					concat(ifnull(date_format(concat(abs_request_form.request_from_date,' ',abs_request_form.request_from_time_),'%d/%m/%Y %H:%i'),''),']C',ifnull(date_format(concat(abs_request_form.request_to_date,' ',abs_request_form.request_to_time_),'%d/%m/%Y %H:%i'),'')) AS date_request,
					abs_request_form.request_total_date as total_date, 
					abs_request_form.request_total_date_hour as total_date_hour, 
					abs_request_form.request_total_time as total_time, 
					abs_request_form.request_subject as reason, 
					concat(concat(ifnull(m_employee_info.firstname,''),' ',ifnull(m_employee_info.lastname,'')),']C',ifnull(m_employee_info.emp_pic,''),']C',ifnull(m_employee_info.emp_id,'')) as owner, 
					abs_request_form.emp_id,
					abs_request_form.request_note as note,
					abs_request_form.request_attach as attach,
					abs_request_form.request_attach_filename as attach_filename,
					'' as run_no,
					'' as approval,
					abs_request_form.request_no_money as without_pay,
					date_format(abs_request_form.create_datetime,'%Y-%m-%d %H:%i:%s') as date_sort 
				from 
					abs_request_form 
				left join 
					m_employee_info on abs_request_form.emp_id = m_employee_info.emp_id
				left join 
					abs_leave_default on abs_request_form.leave_type_id = abs_leave_default.leave_type_id
				where 
					abs_request_form.emp_id in ($emp_id) and abs_request_form.request_status is null {$filter1}
			) UNION (
				select 
					concat('float_request',abs_floating_form.floating_id) as id, 
					abs_floating_form.floating_id as ref_id, 
					'float_request' as type_work, 
					'float_request' as type_id, 
					'REQUEST FOR FLOATING' as type_name, 
					'ร้องขอทำงานทดแทน' as type_name_th, 
					date_format(abs_floating_form.create_datetime,'%d/%m/%Y %H:%i:%s') as date_create,
					concat(ifnull(date_format(concat(abs_floating_form.floating_from_date,' ',abs_floating_form.floating_from_time_),'%d/%m/%Y %H:%i'),''),']C',ifnull(date_format(concat(abs_floating_form.floating_to_date,' ',abs_floating_form.floating_to_time_),'%d/%m/%Y %H:%i'),'')) AS date_request,
					abs_floating_form.floating_total_date as total_date, 
					0 as total_date_hour, 
					abs_floating_form.floating_total_time as total_time, 
					abs_floating_form.floating_subject as reason, 
					concat(concat(ifnull(m_employee_info.firstname,''),' ',ifnull(m_employee_info.lastname,'')),']C',ifnull(m_employee_info.emp_pic,''),']C',ifnull(m_employee_info.emp_id,'')) as owner, 
					abs_floating_form.emp_id,
					abs_floating_form.floating_note as note,
					abs_floating_form.floating_attach as attach,
					abs_floating_form.floating_attach_filename as attach_filename,
					'' as run_no,
					'' as approval,
					'' as without_pay,
					date_format(abs_floating_form.create_datetime,'%Y-%m-%d %H:%i:%s') as date_sort 
				from 
					abs_floating_form 
				left join 
					m_employee_info on abs_floating_form.emp_id = m_employee_info.emp_id		
				where 
					abs_floating_form.emp_id in ($emp_id) and abs_floating_form.floating_status is null {$filter2}
			) UNION (
				select 
					concat('float_leave',abs_floating_leave_form.floating_leave_id) as id, 		
					abs_floating_leave_form.floating_leave_id as ref_id, 
					'float_leave' as type_work, 
					'float_leave' as type_id, 
					'FLOATING LEAVES' as type_name,
					'ขอลาทดแทน' as type_name_th, 
					date_format(abs_floating_leave_form.create_datetime,'%d/%m/%Y %H:%i:%s') as date_create,
					concat(ifnull(date_format(concat(abs_floating_leave_form.floating_leave_from_date,' ',abs_floating_leave_form.floating_leave_from_time_),'%d/%m/%Y %H:%i'),''),']C',ifnull(date_format(concat(abs_floating_leave_form.floating_leave_to_date,' ',abs_floating_leave_form.floating_leave_to_time_),'%d/%m/%Y %H:%i'),'')) AS date_request,
					abs_floating_leave_form.floating_leave_total_date as total_date, 
					0 as total_date_hour ,
					abs_floating_leave_form.floating_leave_total_time as total_time, 
					abs_floating_leave_form.floating_leave_subject as reason, 
					concat(concat(ifnull(m_employee_info.firstname,''),' ',ifnull(m_employee_info.lastname,'')),']C',ifnull(m_employee_info.emp_pic,''),']C',ifnull(m_employee_info.emp_id,'')) as owner, 
					abs_floating_leave_form.emp_id,
					abs_floating_leave_form.floating_leave_note as note,
					abs_floating_leave_form.floating_leave_attach as attach,
					abs_floating_leave_form.floating_leave_attach_filename as attach_filename,
					'' as run_no,
					'' as approval,
					'' as without_pay,
					date_format(abs_floating_leave_form.create_datetime,'%Y-%m-%d %H:%i:%s')  as date_sort 
				from 
					abs_floating_leave_form  
				left join 
					m_employee_info on abs_floating_leave_form.emp_id = m_employee_info.emp_id
				where 
					abs_floating_leave_form.emp_id in ($emp_id) and abs_floating_leave_form.floating_leave_status is null {$filter3}
			) UNION (
				SELECT 
					concat('request_ot',time_request_ot.id_request_ot) as id,
					time_request_ot.id_request_ot as id, 
					'request_ot' as type_work, 
					'request_ot' as type_id, 
					'OT' as type_name, 
					'OT' as type_name_th, 
					concat(date_format(time_request_ot.create_datetime,'%d/%m/%Y %H:%i'),']C',concat(ifnull(m_employee_info.firstname,''),' ',ifnull(m_employee_info.lastname,''))) as date_create,
					concat(ifnull(date_format(time_request_ot.request_ot_in,'%d/%m/%Y %H:%i'),''),']C',ifnull(date_format(time_request_ot.request_ot_out,'%d/%m/%Y %H:%i'),'')) AS date_request,
					0 as total_date, 
					0 as total_date_hour ,
					time_request_ot.total_hrs as total_time, 
					concat('(OT',time_request_ot.request_run_no,') - ',ifnull(time_request_ot.ot_reason,'Request OT')) as reason, 
					concat(concat(ifnull(m_employee_info.firstname,''),' ',ifnull(m_employee_info.lastname,'')),']C',ifnull(m_employee_info.emp_pic,''),']C',ifnull(m_employee_info.emp_id,'')) as owner, 
					time_request_ot.emp_id,
					'' as note,
					'' as attach,
					'' as attach_filename,
					time_request_ot.request_run_no as run_no,
					time_request_ot.ot_status as approval,
					'' as without_pay,
					date_format(time_request_ot.create_datetime,'%Y-%m-%d %H:%i:%s')  as date_sort
				FROM 
					time_request_ot 
				left join 
					m_employee_info ON time_request_ot.emp_id = m_employee_info.emp_id
				WHERE 
					time_request_ot.emp_id in ($emp_id) and time_request_ot.status = 0 and time_request_ot.comp_id = '{$_SESSION['comp_id']}' {$filter4}
			)
		) work";
        require_once $base_include.'/lib/avatar.php'; 
		$primaryKey = 'id';
		$columns = array(
			array( 'db' => 'id', 'dt'  => 'id' ),
			array( 'db' => 'ref_id', 'dt' => 'ref_id'),
			array( 'db' => 'type_work', 'dt' => 'type_work'),
			array( 'db' => 'type_id', 'dt' => 'type_id'),
			array( 'db' => 'type_name', 'dt' => 'type_name'),
			array( 'db' => 'type_name_th', 'dt' => 'type_name_th'),
			array( 'db' => 'date_create', 'dt' => 'date_create'),
			array( 'db' => 'date_request', 'dt' => 'date_request'),
			array( 'db' => 'reason', 'dt' => 'reason'),
			array( 'db' => 'total_date', 'dt' => 'total_date'),
			array( 'db' => 'total_date_hour', 'dt' => 'total_date_hour'),
			array( 'db' => 'emp_id', 'dt' => 'emp_id'),
			array( 'db' => 'run_no', 'dt' => 'run_no'),
			array( 'db' => 'date_sort', 'dt' => 'date_sort'),
			array( 'db' => 'note', 'dt' => 'note'),
			array( 'db' => 'attach', 'dt' => 'attach'),
			array( 'db' => 'without_pay', 'dt' => 'without_pay'),
			array( 'db' => 'total_time', 'dt' => 'total','formatter' => function( $d, $row ) {
				$total_time = $d;
				$total_date = $row['total_date'];
				$total_date_hour = $row['total_date_hour'];
				return $total_time . ']C' . $total_date. ']C' . $total_date_hour;
			}),
			array( 'db' => 'owner', 'dt' => 'owner','formatter' => function( $d, $row ) {
				global $base_path; 
				$emp = explode(']C', $d);
				$emp_name = $emp[0];
				$emp_pic = ($emp[1]) ? avatar_smaller($emp[1], $base_path.'/', $emp_name) : avatar_smaller('', $base_path.'/', $emp_name);
				$emp_id = $emp[2];
				return $emp_pic . ']C' . $emp_id;
			}),
			array( 'db' => 'approval', 'dt' => 'approval','formatter' => function( $d, $row ) {
				$type = $row['type_work'];			
				$head_list = array();
				$head_count = 0;
				$approve_count = 0;		
				$reject_count = 0;		
				$need_count = 0;		
				$request_delete = false;
				if($type != 'request_ot'){
					switch($type){
						case 'leave' :
							$sql_approve=" SELECT abs_request_approve.*,concat(ifnull(m_employee_info.firstname,''),' ',ifnull(m_employee_info.lastname,'')) as emp_name FROM abs_request_approve LEFT JOIN m_employee_info ON abs_request_approve.approve_emp_id = m_employee_info.emp_id WHERE abs_request_approve.request_id = '".$row['ref_id']."' ;";	
						break;
						case 'float_leave' :
							$sql_approve=" SELECT abs_floating_leave_approve.* ,concat(ifnull(m_employee_info.firstname,''),' ',ifnull(m_employee_info.lastname,'')) as emp_name FROM abs_floating_leave_approve LEFT JOIN m_employee_info ON abs_floating_leave_approve.approve_emp_id = m_employee_info.emp_id WHERE abs_floating_leave_approve.floating_leave_id = '".$row['ref_id']."' ";
						break;
						case 'float_request' :
							$sql_approve=" SELECT abs_floating_approve.* ,concat(ifnull(m_employee_info.firstname,''),' ',ifnull(m_employee_info.lastname,'')) as emp_name FROM abs_floating_approve LEFT JOIN m_employee_info ON abs_floating_approve.approve_emp_id = m_employee_info.emp_id WHERE abs_floating_approve.floating_id = '".$row['ref_id']."' ";
						break;
					}
					$rs_approve = query_sqli( $sql_approve);
					$num_approve = mysqli_num_rows($rs_approve);
					if($num_approve > 0){
						$head_count = $num_approve;
						while($row_approve=mysqli_fetch_array($rs_approve)){					
							$prefix_comment=substr($row_approve['approve_comment'],1,4);
							$status_approve='new';						
							$comment_approve='';						
							switch(true){
								case($row_approve['approve_status']=='N' && $row_approve['approve_comment']==''):
									$status_approve='new';
								break;
								case($row_approve['approve_status']=='N' && $prefix_comment=='[Wai'):
									$status_approve='need_info';
									$comment_approve=str_replace("[Waiting]","",$row_approve['approve_comment']);
									$need_count++;
								break;
								case($row_approve['approve_status']=='N' && $prefix_comment=='[Not'):
									$status_approve='reject';
									$comment_approve=str_replace("[Not Approved]","",$row_approve['approve_comment']);
									$reject_count++;
								break;
								case($row_approve['approve_status']=='Y'):
									$status_approve='approve';
									$comment_approve=str_replace("[Approved]","",$row_approve['approve_comment']);
									$approve_count++;
								break;	
							}	
							$head_list[] = array("head_name"=>$row_approve['emp_name'],"status_approve"=>$status_approve,"comment_approve"=>trim($comment_approve));
							$request_delete = ($row_approve['approve_del']=='del')?true:$request_delete;
						}
					}else{
						$sql_head_approve=" SELECT abs_set_approve.*,concat(ifnull(m_employee_info.firstname,''),' ',ifnull(m_employee_info.lastname,'')) as emp_name FROM abs_set_approve LEFT JOIN m_employee_info ON abs_set_approve.approve_emp_id = m_employee_info.emp_id WHERE abs_set_approve.emp_id = '".$row['emp_id']."' ";				
						$rs_head_approve=query_sqli( $sql_head_approve);
						$num_head_approve=mysqli_num_rows($rs_head_approve);
						if($num_head_approve){
							$head_count = $num_head_approve;
							while($row_head_approve=mysqli_fetch_array($rs_head_approve)){	
								$head_list[] = array("head_name"=>$row_head_approve['emp_name'],"status_approve"=>"new");
							}
						}
					}
				}else{ 
					$request_delete = ($d == 4)?true:false;
					$sql_head_approve=" SELECT abs_set_approve.*,concat(ifnull(m_employee_info.firstname,''),' ',ifnull(m_employee_info.lastname,'')) as emp_name FROM abs_set_approve LEFT JOIN m_employee_info ON abs_set_approve.approve_emp_id = m_employee_info.emp_id WHERE abs_set_approve.emp_id = '".$row['emp_id']."' ";				
					$rs_head_approve=query_sqli( $sql_head_approve);
					$num_head_approve=mysqli_num_rows($rs_head_approve);
					if($num_head_approve){
						$head_count = $num_head_approve;					
						while($row_head_approve=mysqli_fetch_array($rs_head_approve)){
							$status_approve='new';							
							$sql_approve = " SELECT time_request_ot_approve.* FROM time_request_ot_approve WHERE time_request_ot_approve.id_request_ot = '".$row['ref_id']."' and time_request_ot_approve.approve_emp_id = '".$row_head_approve['approve_emp_id']."' order by time_request_ot_approve.create_datetime limit 0,1;";	
							$rs_approve=query_sqli( $sql_approve);
							$num_approve=mysqli_num_rows($rs_approve);						
							if($num_approve){
								$row_approve = mysqli_fetch_assoc($rs_approve);
								switch($row_approve['approve_status']){
									case 3:
										$status_approve='need_info';
										$need_count++;
									break;
									case 2:
										$status_approve='reject';
										$reject_count++;
									break;
									case 1:
										$status_approve='approve';
										$approve_count++;
									break;						
								}
							}
							$head_list[] = array("head_name"=>$row_head_approve['emp_name'],"status_approve"=>$status_approve);
						}
					}
				}
				return array(
					"head_list"=>$head_list,
					"head_count"=>$head_count,
					"approve_count"=>$approve_count,
					"reject_count"=>$reject_count,
					"need_count"=>$need_count,
					"request_delete"=>$request_delete
				);
			}),
			array( 'db' => 'id', 'dt' => 'work_permission' ,'formatter' => function( $d, $row ) {
				global $permis_work; 
				return $permis_work;
			}),
		);
		$sql_details = array('user' => $db_username,'pass' => $db_pass_word,'db'   => $db_name,'host' => $db_host);
		require( $base_include.'/lib/ssp-subquery.class.php' );
		echo json_encode( SSP::simple( $_POST, $sql_details, $table, $primaryKey, $columns ));
		exit();
    }
    function getFormatDate($data) {
        return substr($data,-4).'-'.substr($data,3,2).'-'.substr($data,0,2);
    }
    function generateMcKey() {
        $six_digit_random_number = mt_rand(1, 9);
        return $six_digit_random_number;
    }
    function mc_encrypt($encrypt, $mc_key) {	
        $iv = mcrypt_create_iv(mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB), MCRYPT_RAND);
        $passcrypt = trim(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, $mc_key, trim($encrypt), MCRYPT_MODE_ECB, $iv));
        $encode = base64_encode($passcrypt);
        return $encode;
    }
    function mc_decrypt($decrypt, $mc_key) {
        $decoded = base64_decode($decrypt);
        $iv = mcrypt_create_iv(mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB), MCRYPT_RAND);
        $decrypted = trim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $mc_key, trim($decoded), MCRYPT_MODE_ECB, $iv));
        return $decrypted;
    }
    function decryptData($money,$mc_key) {
        return mc_decrypt($money,$mc_key);
    }
?>