<?php
require_once __DIR__ . '/../models/ApprovalModel.php';
class ApprovalController extends Controller {
    private $model;
    public function __construct(){ $this->model = new ApprovalModel(); }
    public function index() {
        $this->view('setup/approval');
    }
}