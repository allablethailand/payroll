<!DOCTYPE html>
<html lang="en">
<head>
<title>Payroll • ORIGAMI SYSTEM</title>
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
<link rel="stylesheet" href="lib/css/pay.css?v=<?php echo time(); ?>">
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
<script src="lib/js/pay.js?v=<?php echo time();?>"></script>
</head>
<body>
<input type="hidden" id="pages" value="payroll">
<div class="container-fluid">
    <?php require_once '../../include_header.php'; ?>
    <div class="row">
        <div class="col-sm-12">
            <div class="row-overflow" style="margin-top:25px;">
                <a href=".payroll_tab" class="active get-payroll main-tab" pages="payroll" data-toggle="tab">
                    <i class="fas fa-coins"></i>
                    <span lang="en">Payroll</span>
                </a>
                <a href=".payroll_tab" class="get-payroll main-tab" pages="approve" data-toggle="tab">
                    <i class="fas fa-check-double"></i>
                    <span lang="en">Approve</span>
                </a>
            </div>
            <div class="filter">
                <a class="toggleFilter"><i class="fas fa-sliders-h"></i></a>
                <label class="countFilter">0</label>
                <div class="row">
                    <div class="col-md-5ths col-sm-4 col-xs-12">
                        <p style="margin:10px auto;">
                            <i class="far fa-calendar"></i>
                            <span lang="en">Date</span>
                        </p>
                        <input type="text" id="filter_date" class="form-control filter-object" placeholder="All date">
                    </div>
                    <div class="col-md-5ths col-sm-4 col-xs-12">
                        <p style="margin:10px auto;">
                            <i class="far fa-building"></i>
                            <span lang="en">Department</span>
                        </p>
                        <select id="filter_department" class="form-control select2-manual filter-object" onchange="buildEmployee(); buildPay();"></select>
                    </div>
                    <div class="col-md-7ths col-sm-4 col-xs-12">
                        <p style="margin:10px auto;">
                            <i class="fas fa-users"></i>
                            <span lang="en">Employee</span>
                        </p>
                        <select id="filter_employee" class="form-control select2-manual filter-object" multiple onchange="buildPay();"></select>
                    </div>
                </div>
            </div>
            <div class="tab_status"></div>
            <input type="hidden" id="filter_status" value="0">
            <div class="payroll_tab tab-pane fade in active">
                <div class="ap-button hidden" style="margin:15px auto;">
                    <button type="button" class="btn btn-success" onclick="approveGroup('Y');"><i class="fas fa-check"></i> <span lang="en">Approve</span></button> 
                    <button type="button" class="btn btn-red" onclick="approveGroup('N');"><i class="fas fa-times"></i> <span lang="en">Not Approve</span></button> 
                    <button type="button" class="btn btn-orange" onclick="approveGroup('I');"><i class="fas fa-info"></i> <span lang="en">Need Information</span></button> 
                </div>
                <table id="tb_pay" class="table table-border">
                    <thead>
                        <tr>
                            <th>
                                <div class="checkbox checkbox-warning checkbox-ap hidden">
                                    <input class="styled migration_all" id="migration_all" type="checkbox" value="Y">
                                    <label for="migration_all"></label>
                                </div>
                            </th>
                            <th lang="en">Payment round</th>
                            <th lang="en">Payment date</th>
                            <th lang="en">Department</th>
                            <th lang="en">Number of employees</th>
                            <th lang="en">Create date</th>
                            <th lang="en">Create by</th>
                            <th lang="en">Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<div id="payrollModal" class="modal fade" role="dialog" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-body"></div>
            <div class="modal-footer"></div>
        </div>
    </div>
</div>
</body>
</html>