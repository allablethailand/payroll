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
    $columnDate = "date_format(NOW(),'%Y%m%d%h%i%s') as DATE_FILENAME,date_format(NOW(),'%Y/%m/%d %H:%i:%s') as report_date";
    $tableDate = "";
    $whereDate = "";
    $Date = select_data($columnDate,$tableDate,$whereDate);
    $date_filename = $Date[0]['DATE_FILENAME'];
    $report_date = $Date[0]['report_date'];
    header('Content-Type:text/html; charset=UTF-8');
    header("Content-Type: application/xls");
    header("Content-Disposition: attachment; filename=$date_filename.xls");
    header("Pragma: no-cache");
    header("Expires: 0");
    $payroll_id = $_POST['payroll_id'];
    $columnData = "date_format(round_start,'%Y/%m/%d') as round_start,date_format(round_end,'%Y/%m/%d') as round_end,date_format(paid_day,'%Y/%m/%d') as paid_day,dept_id,emp_count,remark,payroll_status,data_income,data_deduction,mc_key,comp_id";
    $tableData = "payroll_form";
    $whereData = "where form_id = '{$payroll_id}'";
    $Data = select_data($columnData,$tableData,$whereData);
    $round_start = $Data[0]['round_start'];
    $round_end = $Data[0]['round_end'];
    $paid_day = $Data[0]['paid_day'];
    $dept_id = $Data[0]['dept_id'];
    $emp_count = $Data[0]['emp_count'];
    $payroll_status = $Data[0]['payroll_status'];
    $data_income = $Data[0]['data_income'];
    $data_deduction = $Data[0]['data_deduction'];
    $mc_key = $Data[0]['mc_key'];
    $comp_id = $Data[0]['comp_id'];
    $columnIncome = "income_id,income_name_en,income_name_th,income_flag";
    $tableIncome = "payroll_income_master";
    $whereIncome = "where income_id in ($data_income)";
    $Income = select_data($columnIncome,$tableIncome,$whereIncome);
    $count_income = count($Income);
    $columnDeduction = "deduction_id,deduction_name_en,deduction_name_th,deduction_flag,deduction_module";
    $tableDeduction = "payroll_deduction_master";
    $whereDeduction = "where deduction_id in ($data_deduction)";
    $Deduction = select_data($columnDeduction,$tableDeduction,$whereDeduction);
    $count_deduction = count($Deduction);
    $columnEmp = "emp.emp_id,
                CONCAT(IFNULL(i.firstname,i.firstname_th),' ',IFNULL(i.lastname,i.lastname_th)) AS emp_name,
                emp.emp_code,
                payroll_detail.data_income,
                payroll_detail.data_deduction,
                payroll_detail.amount,
                dept.dept_description";
    $tableEmp = "m_employee emp";
    $whereEmp = "left join 
                    m_employee_info i on i.emp_id = emp.emp_id 
                left join 
                    m_department dept on dept.dept_id = emp.dept_id
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
    function mc_decrypt($decrypt, $mc_key) {
        $decoded = base64_decode($decrypt);
        $iv = mcrypt_create_iv(mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB), MCRYPT_RAND);
        $decrypted = trim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $mc_key, trim($decoded), MCRYPT_MODE_ECB, $iv));
        return $decrypted;
    }
    function decryptData($money,$mc_key) {
        return mc_decrypt($money,$mc_key);
    }
    $column = $count_income+$count_deduction+5;
    $columnComp = "comp_description,comp_description_thai";
    $tableComp = "m_company";
    $whereComp = "where comp_id = '{$comp_id}'";
    $Comp = select_data($columnComp,$tableComp,$whereComp);
    $comp_description = $Comp[0]['comp_description'];
    $comp_description_thai = $Comp[0]['comp_description_thai'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="ie=edge">
</head>
<body>
<table border="0">
    <tr style="font-weight:700;">
        <td colspan="<?php echo $column; ?>">
            <?php echo $comp_description; ?>
            <?php echo ($comp_description_thai) ? '('.$comp_description_thai.')' : '' ?>
        </td>
    </tr>
    <tr style="font-weight:700;">
        <td colspan="<?php echo $column; ?>">
            <i>Report date: <?php echo $report_date; ?></i>
        </td>
    </tr>
    <tr style="font-weight:700;">
        <td colspan="<?php echo $column; ?>">
            Number of employee <?php echo number_format($emp_count); ?>
        </td>
    </tr>
    <tr style="font-weight:700;">
        <td colspan="<?php echo $column; ?>">
            Round <?php echo $round_start; ?> - <?php echo $round_end; ?>
        </td>
    </tr>
    <tr style="font-weight:700;">
        <td colspan="<?php echo $column; ?>">
            Paid <?php echo $paid_day; ?>
        </td>
    </tr>
</table>
<?php
    if($count_emp == 0) {
?>
        <table border="0">
            <tr>
                <td colspan="<?php echo $column; ?>">No data available.</td>
            </tr>
        </table>
<?php
    } else {
?>
        <table border="1">
            <tr style="font-weight:700; text-align:center;">
                <td align="right">No.</td>
                <td align="right">Employee</td>
                <td align="right">Employee Code</td>
                <td align="right">Department</td>
                <td align="right">Amount</td>
<?php
                $i_income = 0;
                while($i_income < $count_income) {
                    $income_name_en = $Income[$i_income]['income_name_en'];
                    $income_name_th = $Income[$i_income]['income_name_th'];
?>
                    <td style="color:#00C292;">
                        <?php echo $income_name_en; ?> (<?php echo $income_name_th; ?>)
                    </td>
<?php
                    ++$i_income;
                }
                $i_deduction = 0;
                while($i_deduction < $count_deduction) {
                    $deduction_name_en = $Deduction[$i_deduction]['deduction_name_en'];
                    $deduction_name_th = $Deduction[$i_deduction]['deduction_name_th'];
?>
                    <td style="color:#a94442;">
                        <?php echo $deduction_name_en; ?> (<?php echo $deduction_name_th; ?>)
                    </td>
<?php
                    ++$i_deduction;
                }
?>
            </tr>
<?php
            $i_emp = 0;
            while($i_emp < $count_emp) {
                $emp_id = $Emp[$i_emp]['emp_id'];
                $emp_code = $Emp[$i_emp]['emp_code'];
                $emp_name = $Emp[$i_emp]['emp_name'];
                $dept_description = $Emp[$i_emp]['dept_description'];
                $amount = htmlspecialchars_decode($Emp[$i_emp]['amount']); 
                $amount = explode(',',$Emp[$i_emp]['amount']);
                $amountLen = decryptData($amount[0],$mc_key);
                $amount_number = '';
                $i = 1;
                while($i <= $amountLen) {
                    $amount_number .= decryptData($amount[$i],$mc_key);
                    ++$i;
                }
                $amount_number = ($amount_number) ? $amount_number : 0;
                $amount_data = number_format($amount_number,2);
                $data_income = explode(',',$Emp[$i_emp]['data_income']);
                $data_deduction = explode(',',$Emp[$i_emp]['data_deduction']);
?>
                <tr>
                    <td align="right"><?php echo $i_emp+1; ?></td>
                    <td align="left"><?php echo $emp_name; ?></td>
                    <td align="left"><?php echo $emp_code; ?></td>
                    <td align="left"><?php echo $dept_description; ?></td>
                    <td align="right"><?php echo $amount_data; ?></td>
<?php
                    $i_income = 0;
                    while($i_income < $count_income) {
                        $money_income = (!empty($data_income[$i_income])) ? decryptData($data_income[$i_income],$mc_key) : 0;
?>
                        <td align="right"><?php echo number_format($money_income,2); ?></td>
<?php
                        ++$i_income;
                    }
                    $i_deduction = 0;
                    while($i_deduction < $count_deduction) {
                        $deduction_flag = $Deduction[$i_deduction]['deduction_flag'];
                        $deduction_module = $Deduction[$i_deduction]['deduction_module'];
                        if(!empty($data_deduction[$i_deduction])) {
                            $money_deduction = decryptData($data_deduction[$i_deduction],$mc_key);
                        } else {
                            $money_deduction = 0;
                        }
?>
                        <td align="right"><?php echo number_format($money_deduction,2); ?></td>
<?php
                        ++$i_deduction;
                    }
?>
                </tr>
<?php
                ++$i_emp;
            }
?>
        </table>
<?php
    }
?>
</body>
</html>