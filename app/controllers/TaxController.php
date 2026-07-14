<?php
require_once __DIR__ . '/../models/TaxModel.php';
class TaxController extends Controller {
    private $model;
    public function __construct(){ $this->model = new TaxModel(); }
    public function index() {
        $this->view('setup/tax');
    }
}