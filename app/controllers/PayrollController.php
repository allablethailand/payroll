<?php
require_once __DIR__ . '/../models/PayrollModel.php';
class PayrollController extends Controller {
    private $model;
    public function __construct(){ $this->model = new PayrollModel(); }
    public function index() {
        $this->view('payroll/index');
    }
}