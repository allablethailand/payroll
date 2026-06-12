<?php
require_once __DIR__ . '/../models/EarningsDeductionsModel.php';
class EarningsDeductionsController extends Controller {
    private $model;
    public function __construct(){ $this->model = new EarningsDeductionsModel(); }
    public function index() {
        $this->view('setup/earnings-deductions');
    }
}