<?php
require_once __DIR__ . '/../models/CycleModel.php';
class CycleController extends Controller {
    private $model;
    public function __construct(){ $this->model = new CycleModel(); }
    public function index() {
        $this->view('setup/cycle');
    }
}