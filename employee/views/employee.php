<!DOCTYPE html>
<html lang="en">
<head>
<title>Employee • ORIGAMI SYSTEM</title>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="/images/logo_new.ico" type="image/x-icon">
<link rel="stylesheet" href="/bootstrap/3.3.6/css/bootstrap.min.css">
<link rel="stylesheet" href="/dist/css/dataTables.bootstrap.min.css">
<link rel="stylesheet" href="/dist/css/select2.min.css" />
<link rel="stylesheet" href="/dist/css/select2-bootstrap.css">
<link rel="stylesheet" href="/dist/css/sweetalert.css">
<link rel="stylesheet" href="/dist/css/filter.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="/dist/daterangepicker/v2/daterangepicker.css">
<link rel="stylesheet" type="text/css" href="/dist/css/jquery-clockpicker.min.css">
<link rel="stylesheet" type="text/css" href="/dist/css/jquery-ui.css">
<link rel="stylesheet" href="/dist/css/origami.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="/dist/css/filter.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="lib/css/employee.css?v=<?php echo time(); ?>">
<script src="/dist/fontawesome-5.11.2/js/all.min.js"></script>
<script src="/dist/fontawesome-5.11.2/js/v4-shims.min.js"></script>
<script src="/dist/fontawesome-5.11.2/js/fontawesome_custom.js?v=<?php echo time(); ?>"></script>
<script src="/bootstrap/3.3.6/js/jquery-2.2.3.min.js"></script>
<script src="/bootstrap/3.3.6/js/bootstrap.min.js"></script>
<script src="/dist/js/jquery.dataTables.min.js"></script>
<script src="/dist/js/dataTables.bootstrap.min.js"></script>
<script src="/dist/lodash/lodash.js"></script>
<script src="/dist/moment/moment.min.js"></script>
<script src="/dist/js/jquery.redirect.js"></script>
<script src="/dist/js/select2-build.min.js?v=<?php echo time(); ?>"></script>
<script src="/dist/tippy/js/popper.min.js"></script>
<script src="/dist/tippy/js/tipsy.min.js"></script>
<script src="/dist/js/sweetalert.min.js"></script>
<script src="/dist/js/moment-with-locales.js"></script>
<script src="/dist/daterangepicker/v2/daterangepicker.js"></script>
<script src="/dist/js/jquery-clockpicker.min.js" type="text/javascript"></script>
<script src="lib/js/employee.js?v=<?php echo time();?>"></script>
</head>
<body>
<input type="hidden" id="pages" value="payroll">
<div class="container-fluid">
    <?php require_once '../../include_header.php'; ?>
    <div class="row">
        <div class="col-sm-12">
            <h4 class="head">
                <i class="fas fa-users"></i> <span lang="en">Employee</span>
            </h4>
            <table class="table table-border" id="tb_employee">
                <thead>
                    <tr>
                        <th></th>
                        <th></th>
                        <th lang="en">Employee Code</th>
                        <th lang="en">Employee Name</th>
                        <th lang="en">Role</th>
                        <th lang="en">Employee Type</th>
                        <th lang="en">Department</th>
                        <th lang="en">Position</th>
                        <th lang="en">Status</th>
                        <th lang="en">Start Date</th>
                        <th lang="en">Work age</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>
<div id="employeeModal" class="modal fade" role="dialog" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" onclick="closeModal('employeeModal');">&times;</button>
                <h5 class="modal-title">Modal Header</h5>
            </div>
            <div class="modal-body"></div>
            <div class="modal-footer"></div>
        </div>
    </div>
</div>
</body>
</html>