<?php
require_once __DIR__ . '/../models/PayrollConfigurationModel.php';
class PayrollConfigurationController extends Controller {
    private $model;
    public function __construct(){ $this->model = new PayrollConfigurationModel(); }
    public function index() {
        $this->view('setup/payroll-configuration');
    }
}