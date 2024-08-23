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
    require_once($base_include."/lib/email_info.php");
    require_once($base_include.'/lib/phpMailer/class.phpmailer.php');
    $fsData = getBucketMaster();
	$filesystem_user = $fsData['fs_access_user'];
	$filesystem_pass = $fsData['fs_access_pass'];
	$filesystem_host = $fsData['fs_host'];
	$filesystem_path = $fsData['fs_access_path'];
	$filesystem_type = $fsData['fs_type'];
	$fs_id = $fsData['fs_id'];
	setBucket($fsData);
    if($_POST['action'] == 'permissionControl') {
        $columnEmail = "email";
        $tableEmail = "m_employee";
        $whereEmail = "where emp_id = '{$_SESSION['emp_id']}'";
        $Email = select_data($columnEmail,$tableEmail,$whereEmail);
        $email = $Email[0]['email'];
        $special_permission = $Data[0]['special_permission'];
        if($special_permission == 'Y') {
            $status = 'Y';
        } else {
            if(empty($_SESSION['permission_flag'])) {
                // ยังไม่ได้มีการ Verify ส่ง Email แจ้งรหัส
                $permission_key = generateKey();
                $_SESSION['permission_flag'] = 'N';
                $_SESSION['permission_key'] = $permission_key;
                $status = 'N';
                $tableInsLog = "payroll_permission_log";
                $columnInsLog = "(
                    emp_id,
                    comp_id,
                    permission_keycode,
                    permission_allow,
                    permission_date
                )";
                $valueInsLog = "(
                    '{$_SESSION['emp_id']}',
                    '{$_SESSION['comp_id']}',
                    '{$_SESSION['permission_key']}',
                    '{$_SESSION['permission_flag']}',
                    NOW()
                )";
                insert_data($tableInsLog,$columnInsLog,$valueInsLog);
                sendEmail($email,$_SESSION['permission_key']);
            } else {
                if($_SESSION['permission_flag'] == 'N') {
                    // ส่ง Email แจ้งรหัสแล้ว บังคับให้ใส่รหัส 
                    $status = 'N';
                } else {
                    // เข้าใช้งานได้เลย 
                    $status = 'Y';
                }
                $tableUpdData = "payroll_permission_log";
                $valueUpdData = "permission_allow = '{$status}',permission_date = NOW()";
                $whereUpdData = "emp_id = '{$_SESSION['emp_id']}' and permission_keycode = '{$_SESSION['permission_key']}'";
                update_data($tableUpdData,$valueUpdData,$whereUpdData);
            }
        }
        echo json_encode([
            'status' => $status,
            'permission_key' => $_SESSION['permission_key'],
            'email' => $email
        ]);
    }
    if($_POST['action'] == 'checkPassCode') {
        $passCode = $_POST['passCode'];
        if($passCode == $_SESSION['permission_key']) {
            $_SESSION['permission_flag'] = 'Y';
            $tableUpdData = "payroll_permission_log";
            $valueUpdData = "permission_allow = 'Y',permission_date = NOW()";
            $whereUpdData = "emp_id = '{$_SESSION['emp_id']}' and permission_keycode = '{$_SESSION['permission_key']}'";
            update_data($tableUpdData,$valueUpdData,$whereUpdData);
            echo json_encode(['status' => true]);
        } else {
            $tableUpdData = "payroll_permission_log";
            $valueUpdData = "permission_allow = 'N',permission_date = NOW()";
            $whereUpdData = "emp_id = '{$_SESSION['emp_id']}' and permission_keycode = '{$_SESSION['permission_key']}'";
            update_data($tableUpdData,$valueUpdData,$whereUpdData);
            echo json_encode(['status' => false]);
        }
    }
    if($_POST['action'] == 'resendPasscode') {
        $columnEmail = "email";
        $tableEmail = "m_employee";
        $whereEmail = "where emp_id = '{$_SESSION['emp_id']}'";
        $Email = select_data($columnEmail,$tableEmail,$whereEmail);
        $email = $Email[0]['email'];
        sendEmail($email,$_SESSION['permission_key']);
        echo json_encode(['status' => true]);
    }
    function generateKey() {
        $six_digit_random_number = mt_rand(100000, 999999);
        return $six_digit_random_number;
    }
    function sendEmail($email,$permission_key) {
        global $email_origami_username,$email_origami_password,$email_origami_host,$email_origami_port;
        $subject = 'ORIGAMI [Payroll]-Login verification code '.$permission_key;
        $header = "From: ".$email_origami_username;
        $body = '
			<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
            <html xmlns="http://www.w3.org/1999/xhtml">
            <head>
            <meta name="viewport" content="width=device-width" />
            <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
            <title>ORIGAMI</title>
            </head>
            <body style="margin:0px; background: #f8f8f8;padding:0px 30px 0px 30px;">
            <div width="100%" style="background: #f8f8f8; padding: 0px 0px; font-family:arial; line-height:18px; height:100%;  width: 100%; color: #514d6a;">
                <div style="max-width: 700px; padding:50px 0;  margin: 0px auto; font-size: 14px">
                    <table border="0" cellpadding="0" cellspacing="0" style="width: 100%;">
                        <tbody>
                            <tr>
                                <td style="vertical-align: top; padding-bottom:30px;" align="center">
                                    <a href="https://www.origami.life/login.php" target="_blank" style="color: #514d6a;font-family:arial;text-decoration:none">
                                        <img src="https://www.origami.life/images/origami_logo.png" width="45" alt="ORIGAMI" style="border:none">
                                        <div style="width:71px;overflow:hidden;">
                                            <b style="font-size:16px;color: #514d6a;">ORIGAMI</b><br/>
                                            <p style="padding-left:2px;margin-top:0px;letter-spacing:0.45em;border-top:solid 1px #514d6a;color: #514d6a;font-size:10px;width:100%;text-align:center;">SYSTEM</p>
                                        </div>
                                    </a>  
                                </td>
                            </tr>
                            <tr>
                                <td align="center">Please use the following code to help verify your identity:</td>
                            </tr>
                            <tr>
                                <td align="center"><h3>Your code is: '.$permission_key.'</h3></td>
                            </tr>
                            <tr>
                                <td align="center">Origami System</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            </body>
            </html>
        ';
        $mail = new PHPMailer();
        $mail->Subject = $subject;
        $mail->FromName = $header;
        $mail->Username = $email_origami_username;
		$mail->Password = $email_origami_password;
        $mail->AddAddress($email);
        $mail->IsHTML(true);
		$mail->Body = $body;
        $mail->Host = $email_origami_host; 
		$mail->Port = $email_origami_port;
		$mail->IsSMTP(); 
		$mail->SMTPAuth = true;
		$mail->From = $mail->Username;
		$mail->Send();
		$mail->ClearAddresses();
        return true;
    }
?>