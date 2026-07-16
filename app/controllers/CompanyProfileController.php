<?php
require_once __DIR__ . '/../models/CompanyProfileModel.php';
class CompanyProfileController extends Controller {
    private $model;
    public function __construct(){ $this->model = new CompanyProfileModel(); }
    public function index() {
        $this->view('setup/company-profile');
    }
}