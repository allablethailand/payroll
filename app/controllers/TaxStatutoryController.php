<?php
require_once __DIR__ . '/../models/TaxStatutoryModel.php';
class TaxStatutoryController extends Controller {
    private $model;
    public function __construct(){ $this->model = new TaxStatutoryModel(); }
    public function index() {
        $this->view('setup/tax-statutory');
    }
}