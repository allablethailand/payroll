<!DOCTYPE html>
<html lang="en">
<head>
<title>Payroll Setting • ORIGAMI SYSTEM</title>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="/images/logo_new.ico" type="image/x-icon">
<link rel="stylesheet" href="/bootstrap/3.3.6/css/bootstrap.min.css">
<link rel="stylesheet" href="/dist/css/dataTables.bootstrap.min.css">
<link rel="stylesheet" href="/dist/css/select2.min.css" />
<link rel="stylesheet" href="/dist/css/select2-bootstrap.css">
<link rel="stylesheet" href="/dist/css/sweetalert.css">
<link rel="stylesheet" href="/dist/daterangepicker/v2/daterangepicker.css">
<link rel="stylesheet" href="/dist/css/jquery-clockpicker.min.css">
<link rel="stylesheet" href="/dist/css/jquery-ui.css">
<link rel="stylesheet" href="/dist/css/origami.css?v=<?php echo time(); ?>">
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
<script src="/payroll/assets/js/setting.js?v=<?php echo time();?>"></script>
</head>
<body>
<?php require_once '../include_header.php'; ?>
<div class="container-fluid">
    <div class="row">
        <ul class="nav origami-nav nav-tabs">
            <li class="active">
                <a href=".payroll-tab" data-toggle="tab" data-page="period">
                   <i class="far fa-calendar"></i> <span lang="en">Payroll Period</span>
                </a>
            </li>
            <li class="hidden">
                <a href=".payroll-tab" data-toggle="tab" data-page="revenue">
                    <span class="text-green"><i class="fas fa-coins"></i> <span lang="en">Revenue</span></span>
                </a>
            </li>
            <li class="hidden">
                <a href=".payroll-tab" data-toggle="tab" data-page="deductions">
                    <span class="text-red"><i class="fas fa-coins"></i> <span lang="en">Deductions</span></span>
                </a>
            </li>
            <li class="dropdown pull-right hidden other-menu">
                <a class="dropdown-toggle" data-toggle="dropdown" href="#">More <span class="caret"></span></a>
                <ul class="dropdown-menu other-list"></ul>
            </li>
        </ul>
        <div class="tab-content">
            <div class="payroll-tab tab-pane fade in active">
                <div class="payroll-container"></div>
            </div>
        </div>
    </div>
</div>
</html>