<!DOCTYPE html>
<html lang="en">
<head>
<title>Payroll Form • ORIGAMI SYSTEM</title>
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
<script src="lib/js/detail.js?v=<?php echo time();?>"></script>
</head>
<body>
<input type="hidden" id="payroll_id" value="<?php echo $_POST['payroll_id']; ?>">
<div class="container-fluid">
    <?php require_once '../../include_header.php'; ?>
    <div class="row">
        <div class="col-sm-12">
            <div class="timeline"></div>
            <div class="pay">
                <div class="row-overflow">
                    <a class="pay-navbar form active" pages="form"><i class="fas fa-coins"></i> <span lang="en">Form</span></a>
                    <a class="pay-navbar payroll navAfterLogin hidden" pages="payroll"><i class="fas fa-coins"></i> <span lang="en">Payment list</span></a>
                </div>
            </div>
            <div class="text-right navAfterLogin hidden">
                <button type="button" class="btn btn-green btn-paid hidden" onclick="paidData();"><i class="fas fa-hand-holding-usd"></i> <span lang="en">Paid</span></button> 
                <button type="button" class="btn btn-orange btn-send-approve" onclick="sendToApprove();"><i class="fas fa-share-square"></i> <span lang="en">Send to approve</span></button> 
                <button type="button" class="btn btn-white btn-excel text-green" onclick="exportToExcel();"><i class="fas fa-file-excel"></i> Excel</button>
            </div>
            <div class="tab-content"></div>
        </div>
    </div>
</div>
<div class="modal fade payrollModal" role="dialog" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"></h5>
            </div>
            <div class="modal-body"></div>
            <div class="modal-footer"></div>
        </div>
    </div>
</div>
<div class="modal fade permissionModal" role="dialog" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-body"></div>
        </div>
    </div>
</div>
<div class="modal fade calculateModal" role="dialog" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"></h5>
            </div>
            <div class="modal-body"></div>
            <div class="modal-footer"></div>
        </div>
    </div>
</div>
<div class="modal fade joinModal" role="dialog" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-body"></div>
            <div class="modal-footer"></div>
        </div>
    </div>
</div>
<div class="show-detail"></div>
</body>
</html>