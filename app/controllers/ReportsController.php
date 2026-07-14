<?php
require_once __DIR__ . '/../models/ReportsModel.php';
class ReportsController extends Controller {
    private $model;
    public function __construct(){ $this->model = new ReportsModel(); }
    public function index() {
        $this->view('reports/index');
    }
}